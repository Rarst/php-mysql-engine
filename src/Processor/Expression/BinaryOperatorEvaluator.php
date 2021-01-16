<?php
namespace Vimeo\MysqlEngine\Processor\Expression;

use Vimeo\MysqlEngine\Processor\SQLFakeRuntimeException;
use Vimeo\MysqlEngine\Query\Expression\BinaryOperatorExpression;
use Vimeo\MysqlEngine\Query\Expression\IntervalOperatorExpression;
use Vimeo\MysqlEngine\Query\Expression\RowExpression;
use Vimeo\MysqlEngine\Query\Expression\ConstantExpression;
use Vimeo\MysqlEngine\Query\Expression\FunctionExpression;
use Vimeo\MysqlEngine\Query\Expression\VariableExpression;
use Vimeo\MysqlEngine\Processor\Scope;
use Vimeo\MysqlEngine\Schema\Column;
use Vimeo\MysqlEngine\TokenType;

final class BinaryOperatorEvaluator
{
    /**
     * @param array<string, mixed> $row
     * @param array<string, Column> $columns
     */
    public static function evaluate(
        \Vimeo\MysqlEngine\FakePdo $conn,
        Scope $scope,
        BinaryOperatorExpression $expr,
        array $row,
        array $columns
    ) {
        $right = $expr->right;
        $left = $expr->left;

        if ($left instanceof RowExpression) {
            if (!$right instanceof RowExpression) {
                throw new SQLFakeRuntimeException("Expected row expression on RHS of {$expr->operator} operand");
            }

            return (int) self::evaluateRowComparison($conn, $scope, $expr, $left, $right, $row, $columns);
        }

        if ($right === null) {
            throw new SQLFakeRuntimeException("Attempted to evaluate BinaryOperatorExpression with no right operand");
        }

        if ($expr->operator === 'COLLATE') {
            $l_value = Evaluator::evaluate($conn, $scope, $left, $row, $columns);

            return $l_value;
        }

        if ($right instanceof IntervalOperatorExpression
            && ($expr->operator === '+' || $expr->operator === '-')
        ) {
            $functionName = $expr->operator === '+' ? 'DATE_ADD' : 'DATE_SUB';

            return FunctionEvaluator::evaluate(
                $conn,
                $scope,
                new FunctionExpression(
                    new \Vimeo\MysqlEngine\Parser\Token(
                        TokenType::SQLFUNCTION,
                        $functionName,
                        $functionName
                    ),
                    [
                        $left,
                        $right,
                    ],
                    false
                ),
                $row,
                $columns
            );
        }

        $l_value = Evaluator::evaluate($conn, $scope, $left, $row, $columns);
        $r_value = Evaluator::evaluate($conn, $scope, $right, $row, $columns);

        $l_type = Evaluator::getColumnSchema($left, $scope, $columns);
        $r_type = Evaluator::getColumnSchema($right, $scope, $columns);

        $l_value = self::maybeUnrollGroupedDataset($l_value);
        $r_value = self::maybeUnrollGroupedDataset($r_value);

        $as_string = $left->getType() == TokenType::STRING_CONSTANT || $right->getType() == TokenType::STRING_CONSTANT;

        if ($l_type->getPhpType() === 'string' && $r_type->getPhpType() === 'string') {
            if (\preg_match('/^[0-9]{2,4}-[0-1][0-9]-[0-3][0-9] [0-2][0-9]:[0-5][0-9]:[0-5][0-9]$/', $l_value)
                && \preg_match('/^[0-9]{2,4}-[0-1][0-9]-[0-3][0-9]$/', $r_value)
            ) {
                $r_value .= ' 00:00:00';
            } elseif (\preg_match('/^[0-9]{2,4}-[0-1][0-9]-[0-3][0-9] [0-2][0-9]:[0-5][0-9]:[0-5][0-9]$/', $r_value)
                && \preg_match('/^[0-9]{2,4}-[0-1][0-9]-[0-3][0-9]$/', $l_value)
            ) {
                $l_value .= ' 00:00:00';
            }

            $as_string = true;
        }

        switch ($expr->operator) {
            case '':
                throw new SQLFakeRuntimeException('Attempted to evaluate BinaryOperatorExpression with empty operator');

            case 'AND':
                if ((bool) $l_value && (bool) $r_value) {
                    return (int) (!$expr->negated);
                }
                return (int) $expr->negated;

            case 'OR':
                if ((bool) $l_value || (bool) $r_value) {
                    return (int) (!$expr->negated);
                }
                return (int) $expr->negated;

            case '=':
                return $l_value == $r_value ? 1 : 0 ^ $expr->negatedInt;

            case '<>':
            case '!=':
                if ($as_string) {
                    return (string) $l_value != (string) $r_value ? 1 : 0 ^ $expr->negatedInt;
                }

                return (float) $l_value != (float) $r_value ? 1 : 0 ^ $expr->negatedInt;

            case '>':
                if ($as_string) {
                    return (string) $l_value > (string) $r_value ? 1 : 0 ^ $expr->negatedInt;
                }

                return (float) $l_value > (float) $r_value ? 1 : 0 ^ $expr->negatedInt;
                // no break
            case '>=':
                if ($as_string) {
                    return (string) $l_value >= (string) $r_value ? 1 : 0 ^ $expr->negatedInt;
                }

                return (float) $l_value >= (float) $r_value ? 1 : 0 ^ $expr->negatedInt;

            case '<':
                if ($as_string) {
                    return (string) $l_value < (string) $r_value ? 1 : 0 ^ $expr->negatedInt;
                }

                return (float) $l_value < (float) $r_value ? 1 : 0 ^ $expr->negatedInt;

            case '<=':
                if ($as_string) {
                    return (string) $l_value <= (string) $r_value ? 1 : 0 ^ $expr->negatedInt;
                }

                return (float) $l_value <= (float) $r_value ? 1 : 0 ^ $expr->negatedInt;

            case '*':
            case '%':
            case 'MOD':
            case '-':
            case '+':
            case '<<':
            case '>>':
            case '/':
            case 'DIV':
            case '|':
            case '&':
                $left_number = self::extractNumericValue($l_value);
                $right_number = self::extractNumericValue($r_value);

                switch ($expr->operator) {
                    case '*':
                        return $left_number * $right_number;
                    case '%':
                    case 'MOD':
                        return \fmod((double) $left_number, (double) $right_number);
                    case '/':
                        return $left_number / $right_number;
                    case 'DIV':
                        return (int) ($left_number / $right_number);
                    case '-':
                        return $left_number - $right_number;
                    case '+':
                        return $left_number + $right_number;
                    case '<<':
                        return (int) $left_number << (int) $right_number;
                    case '>>':
                        return (int) $left_number >> (int) $right_number;
                    case '|':
                        return (int) $left_number | (int) $right_number;
                    case '&':
                        return (int) $left_number & (int) $right_number;
                }

                throw new SQLFakeRuntimeException("Operator recognized but not implemented");

            case 'LIKE':
                $left_string = (string) Evaluator::evaluate($conn, $scope, $left, $row, $columns);

                if (!$right instanceof ConstantExpression) {
                    throw new SQLFakeRuntimeException("LIKE pattern should be a constant string");
                }

                $pattern = (string) $r_value;
                $start_pattern = '^';
                $end_pattern = '$';

                if ($pattern[0] === '%') {
                    $start_pattern = '';
                    $pattern = \substr($pattern, 1);
                }

                if (\substr($pattern, -1) === '%') {
                    $end_pattern = '';
                    $pattern = \substr($pattern, 0, -1);
                }

                // escape all + characters
                $pattern = \preg_quote($pattern, '/');
                $pattern = \preg_replace('/(?<!\\\\)%/', '.*?', $pattern);
                $pattern = \preg_replace('/(?<!\\\\)_/', '.', $pattern);
                $regex = '/' . $start_pattern . $pattern . $end_pattern . '/s';

                return ((bool) \preg_match($regex, $left_string) ? 1 : 0) ^ $expr->negatedInt;

            case 'IS':
                if (!$right instanceof ConstantExpression) {
                    throw new SQLFakeRuntimeException("Unsupported right operand for IS keyword");
                }
                $val = Evaluator::evaluate($conn, $scope, $left, $row, $columns);
                $r = $r_value;
                if ($r === null) {
                    return ($val === null ? 1 : 0) ^ $expr->negatedInt;
                }
                throw new SQLFakeRuntimeException("Unsupported right operand for IS keyword");

            case 'RLIKE':
            case 'REGEXP':
                $left_string = (string) Evaluator::evaluate($conn, $scope, $left, $row, $columns);
                $case_insensitive = 'i';
                if ($right instanceof FunctionExpression && $right->functionName() == 'BINARY') {
                    $case_insensitive = '';
                }
                $pattern = (string) $r_value;
                $regex = '/' . $pattern . '/' . $case_insensitive;
                return ((bool) \preg_match($regex, $left_string) ? 1 : 0) ^ $expr->negatedInt;

            case ':=':
                if (!$left instanceof VariableExpression) {
                    throw new SQLFakeRuntimeException("Unsupported left operand for variable assignment");
                }

                $scope->variables[$left->variableName] = $r_value;

                return $scope->variables[$left->variableName];

            case '&&':
            case 'BINARY':
            case 'COLLATE':
            case '^':
            case '<=>':
            case '||':
            case 'XOR':
            case 'SOUNDS':
            case 'ANY':
            case 'SOME':
            default:
                throw new SQLFakeRuntimeException("Operator {$expr->operator} not implemented in SQLFake");
        }
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, Column> $columns
     */
    public static function getColumnSchema(
        BinaryOperatorExpression $expr,
        Scope $scope,
        array $columns
    ) {
        $left = $expr->left;
        $right = $expr->right;

        if ($left instanceof RowExpression) {
            if (!$right instanceof RowExpression) {
                throw new SQLFakeRuntimeException("Expected row expression on RHS of {$expr->operator} operand");
            }

            return new Column\TinyInt(true, 1);
        }

        if ($right === null) {
            throw new SQLFakeRuntimeException("Attempted to evaluate BinaryOperatorExpression with no right operand");
        }

        if ($right instanceof IntervalOperatorExpression
            && ($expr->operator === '+' || $expr->operator === '-')
        ) {
            $functionName = $expr->operator === '+' ? 'DATE_ADD' : 'DATE_SUB';

            return new Column\DateTime();
        }

        if ($expr->operator === 'COLLATE') {
            return new Column\Varchar(255);
        }

        $l_type = Evaluator::getColumnSchema($left, $scope, $columns);
        $r_type = Evaluator::getColumnSchema($right, $scope, $columns);

        switch ($expr->operator) {
            case '':
                throw new SQLFakeRuntimeException('Attempted to evaluate BinaryOperatorExpression with empty operator');

            case 'AND':
            case 'OR':
            case '=':
            case '<>':
            case '!=':
            case '>':
            case '>=':
            case '<':
            case '<=':
            case 'LIKE':
            case 'IS':
            case 'RLIKE':
            case 'REGEXP':
                return new Column\TinyInt(true, 1);

            case '-':
            case '+':
            case '*':
                if ($l_type instanceof Column\IntegerColumn && $r_type instanceof Column\IntegerColumn) {
                    return new Column\IntColumn(false, 11);
                }

                return new Column\FloatColumn(10, 2);

            case '%':
            case 'MOD':
                if ($l_type instanceof Column\IntegerColumn) {
                    return new Column\IntColumn(true, 11);
                }

                return new Column\FloatColumn(10, 2);

            case 'DIV':
                return new Column\IntColumn(false, 11);

            case '/':
                return new Column\FloatColumn(10, 2);

            case '<<':
            case '>>':
            case '|':
            case '&':
                return new Column\IntColumn(false, 11);

            case ':=':
                return $r_type;
        }

        return new Column\Varchar(255);
    }

    /**
     * @param mixed $data
     *
     * @return mixed
     */
    private static function maybeUnrollGroupedDataset($data)
    {
        if (\is_array($data)) {
            if (!$data) {
                return null;
            }

            if (\count($data) === 1) {
                $data = reset($data);

                if (\is_array($data)) {
                    if (\count($data) === 1) {
                        return reset($data);
                    }

                    throw new SQLFakeRuntimeException("Subquery should return a single column");
                }

                return reset($data);
            }

            throw new SQLFakeRuntimeException("Subquery should return a single column");
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, Column> $columns
     *
     * @return bool
     */
    private static function evaluateRowComparison(
        \Vimeo\MysqlEngine\FakePdo $conn,
        Scope $scope,
        BinaryOperatorExpression $expr,
        RowExpression $left,
        RowExpression $right,
        array $row,
        array $columns
    ) {
        $left_elems = Evaluator::evaluate($conn, $scope, $left, $row, $columns);
        assert(\is_array($left_elems), "RowExpression must return vec");
        $right_elems = Evaluator::evaluate($conn, $scope, $right, $row, $columns);
        assert(\is_array($right_elems), "RowExpression must return vec");
        if (\count($left_elems) !== \count($right_elems)) {
            throw new SQLFakeRuntimeException("Mismatched column count in row comparison expression");
        }
        $last_index = \array_key_last($left_elems);
        $match = true;
        foreach ($left_elems as $index => $le) {
            $re = $right_elems[$index];
            if ($le == $re && $index !== $last_index) {
                continue;
            }
            switch ($expr->operator) {
                case '=':
                    return $le == $re;
                case '<>':
                case '!=':
                    return $le != $re;
                case '>':
                    return $le > $re;
                case '>=':
                    return $le >= $re;
                case '<':
                    return $le < $re;
                case '<=':
                    return $le <= $re;
                default:
                    throw new SQLFakeRuntimeException("Operand {$expr->operator} should contain 1 column(s)");
            }
        }
        return false;
    }

    /**
     * @param scalar|array<scalar> $val
     *
     * @return numeric
     */
    protected static function extractNumericValue($val)
    {
        if (\is_array($val)) {
            if (0 === \count($val)) {
                $val = 0;
            } else {
                $val = self::extractNumericValue(reset($val));
            }
        }

        return \strpos((string) $val, '.') !== false ? (double) $val : (int) $val;
    }
}
