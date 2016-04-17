<?php

namespace CodeInspector;

use PhpParser;

class TypeChecker
{
    protected $_absolute_class;
    protected $_namespace;
    protected $_checker;

    const ASSIGNMENT_TO_RIGHT = 1;
    const ASSIGNMENT_TO_LEFT = -1;

    public function __construct(StatementsSource $source, StatementsChecker $statements_checker)
    {
        $this->_absolute_class = $source->getAbsoluteClass();
        $this->_namespace = $source->getNamespace();
        $this->_checker = $statements_checker;
    }

    public static function check($return_type, $method_id, $arg_offset, $current_class, $file_name, $line_number)
    {
        if ($return_type === 'mixed') {
            return true;
        }

        $method_params = ClassMethodChecker::getMethodParams($method_id);

        if ($arg_offset >= count($method_params)) {
            return true;
        }

        $expected_type = $method_params[$arg_offset]['type'];

        if (!$expected_type) {
            return true;
        }

        if ($return_type === 'null') {
            if ($method_params[$arg_offset]['is_nullable']) {
                return true;
            }

            throw new CodeException('Argument ' . ($arg_offset + 1) . ' of ' . $method_id . ' cannot be null, but possibly null value was supplied', $file_name, $line_number);
        }

        // Remove generic type
        $return_type = preg_replace('/\<[A-Za-z0-9' . '\\\\' . ']+\>/', '', $return_type);

        if ($return_type === $expected_type) {
            return true;
        }

        if (StatementsChecker::isMock($return_type)) {
            return true;
        }

        if (!is_subclass_of($return_type, $expected_type, true)) {
            if (is_subclass_of($expected_type, $return_type, true)) {
                //echo('Warning: dangerous type coercion in ' . $file_name . ' on line ' . $line_number . PHP_EOL);
                return true;
            }

            throw new CodeException('Argument ' . ($arg_offset + 1) . ' of ' . $method_id . ' has incorrect type of ' . $return_type . ', expecting ' . $expected_type, $file_name, $line_number);
        }

        return true;
    }

    public function getType(PhpParser\Node\Expr $stmt, array $vars_in_scope)
    {
        if ($stmt instanceof PhpParser\Node\Expr\Variable && is_string($stmt->name)) {
            if ($stmt->name === 'this') {
                return $this->_absolute_class;
            }
            elseif (isset($vars_in_scope[$stmt->name]) && is_string($vars_in_scope[$stmt->name])) {
                return $vars_in_scope[$stmt->name];
            }
        }
        elseif ($stmt instanceof PhpParser\Node\Expr\PropertyFetch &&
            $stmt->var instanceof PhpParser\Node\Expr\Variable &&
            $stmt->var->name === 'this' &&
            is_string($stmt->name)
        ) {
            $property_id = $this->_absolute_class . '::' . $stmt->name;

            if (isset($vars_in_scope[$property_id])) {
                return $vars_in_scope[$property_id];
            }
        }

        if (isset($stmt->returnType)) {
            return $stmt->returnType;
        }

        return null;
    }

    /**
     * Gets all the type assertions in a conditional
     *
     * @param  PhpParser\Node\Expr $stmt
     * @return array
     */
    public function getTypeAssertions(PhpParser\Node\Expr $conditional, $check_boolean_and = false)
    {
        $if_types = [];

        if ($conditional instanceof PhpParser\Node\Expr\Instanceof_) {
            $instanceof_type = $this->_getInstanceOfTypes($conditional);

            if ($instanceof_type) {
                $var_name = $this->_getVariable($conditional->expr);
                if ($var_name) {
                    $if_types[$var_name] = $instanceof_type;
                }
            }
        }
        else if ($var_name = $this->_getVariable($conditional)) {
            $if_types[$var_name] = '!empty';
        }
        else if ($conditional instanceof PhpParser\Node\Expr\BooleanNot) {
            if ($conditional->expr instanceof PhpParser\Node\Expr\Instanceof_) {
                $instanceof_type = $this->_getInstanceOfTypes($conditional->expr);

                if ($instanceof_type) {
                    $var_name = $this->_getVariable($conditional->expr->expr);
                    if ($var_name) {
                        $if_types[$var_name] = '!' . $instanceof_type;
                    }
                }
            }
            else if ($var_name = $this->_getVariable($conditional->expr)) {
                $if_types[$var_name] = 'empty';
            }
            else if ($conditional->expr instanceof PhpParser\Node\Expr\BinaryOp\Identical) {
                $null_position = self::_hasNullVariable($conditional->expr);

                if ($null_position !== null) {
                    if ($null_position === self::ASSIGNMENT_TO_RIGHT) {
                        $var_name = $this->_getVariable($conditional->expr->left);
                    }
                    else if ($null_position === self::ASSIGNMENT_TO_LEFT) {
                        $var_name = $this->_getVariable($conditional->epxr->right);
                    }
                    else {
                        throw new \InvalidArgumentException('Bad null variable position');
                    }

                    if ($var_name) {
                        $if_types[$var_name] = '!null';
                    }
                }
            }
            else if ($conditional->expr instanceof PhpParser\Node\Expr\BinaryOp\NotIdentical) {
                $null_position = self::_hasNullVariable($conditional->expr);

                if ($null_position !== null) {
                    if ($null_position === self::ASSIGNMENT_TO_RIGHT) {
                        $var_name = $this->_getVariable($conditional->expr->left);
                    }
                    else if ($null_position === self::ASSIGNMENT_TO_LEFT) {
                        $var_name = $this->_getVariable($conditional->epxr->right);
                    }
                    else {
                        throw new \InvalidArgumentException('Bad null variable position');
                    }

                    if ($var_name) {
                        $if_types[$var_name] = 'null';
                    }
                }
            }
            else if ($conditional->expr instanceof PhpParser\Node\Expr\Empty_) {
                $var_name = $this->_getVariable($conditional->expr->expr);

                if ($var_name) {
                    $if_types[$var_name] = '!empty';
                }
            }
            else if (self::_hasNullCheck($conditional->expr)) {
                $var_name = $this->_getVariable($conditional->expr->args[0]->value);
                $if_types[$var_name] = '!null';
            }
            else if (self::_hasArrayCheck($conditional->expr)) {
                $var_name = $this->_getVariable($conditional->expr->args[0]->value);
                $if_types[$var_name] = '!array';
            }
        }
        else if ($conditional instanceof PhpParser\Node\Expr\BinaryOp\Identical) {
            $null_position = self::_hasNullVariable($conditional);

            if ($null_position !== null) {
                if ($null_position === self::ASSIGNMENT_TO_RIGHT) {
                    $var_name = $this->_getVariable($conditional->left);
                }
                else if ($null_position === self::ASSIGNMENT_TO_LEFT) {
                    $var_name = $this->_getVariable($conditional->right);
                }
                else {
                    throw new \InvalidArgumentException('Bad null variable position');
                }

                if ($var_name) {
                    $if_types[$var_name] = 'null';
                }
            }
        }
        else if ($conditional instanceof PhpParser\Node\Expr\BinaryOp\NotIdentical) {
            $null_position = self::_hasNullVariable($conditional);

            if ($null_position !== null) {
                if ($null_position === self::ASSIGNMENT_TO_RIGHT) {
                    $var_name = $this->_getVariable($conditional->left);
                }
                else if ($null_position === self::ASSIGNMENT_TO_LEFT) {
                    $var_name = $this->_getVariable($conditional->right);
                }
                else {
                    throw new \InvalidArgumentException('Bad null variable position');
                }

                if ($var_name) {
                    $if_types[$var_name] = '!null';
                }
            }
        }
        else if (self::_hasNullCheck($conditional)) {
            $var_name = $this->_getVariable($conditional->args[0]->value);
            $if_types[$var_name] = 'null';
        }
        else if (self::_hasArrayCheck($conditional)) {
            $var_name = $this->_getVariable($conditional->args[0]->value);
            $if_types[$var_name] = 'array';
        }
        else if ($conditional instanceof PhpParser\Node\Expr\Empty_) {
            $var_name = $this->_getVariable($conditional->expr);
            if ($var_name) {
                $if_types[$var_name] = 'empty';
            }
        }
        else if ($conditional instanceof PhpParser\Node\Expr\BinaryOp\BooleanOr) {
            $left_assertions = $this->getTypeAssertions($conditional->left, false);
            $right_assertions = $this->getTypeAssertions($conditional->right, false);

            $keys = array_merge(array_keys($left_assertions), array_keys($right_assertions));
            $keys = array_unique($keys);

            foreach ($keys as $key) {
                if (isset($left_assertions[$key]) && isset($right_assertions[$key])) {
                    $if_types[$key] = $left_assertions[$key] . '|' . $right_assertions[$key];
                }
                else if (isset($left_assertions[$key])) {
                    $if_types[$key] = $left_assertions[$key];
                }
                else {
                    $if_types[$key] = $right_assertions[$key];
                }
            }
        }
        else if ($check_boolean_and && $conditional instanceof PhpParser\Node\Expr\BinaryOp\BooleanAnd) {
            $left_assertions = $this->getTypeAssertions($conditional->left, $check_boolean_and);
            $right_assertions = $this->getTypeAssertions($conditional->right, $check_boolean_and);

            $keys = array_merge(array_keys($left_assertions), array_keys($right_assertions));
            $keys = array_unique($keys);

            foreach ($keys as $key) {
                if (isset($left_assertions[$key]) && isset($right_assertions[$key])) {
                    if ($left_assertions[$key][0] !== '!' && $right_assertions[$key][0] !== '!') {
                        $if_types[$key] = $left_assertions[$key] . '&' . $right_assertions[$key];
                    }
                    else {
                        $if_types[$key] = $right_assertions[$key];
                    }
                }
                else if (isset($left_assertions[$key])) {
                    $if_types[$key] = $left_assertions[$key];
                }
                else {
                    $if_types[$key] = $right_assertions[$key];
                }
            }
        }

        return $if_types;
    }

    protected function _getInstanceOfTypes(PhpParser\Node\Expr\Instanceof_ $stmt)
    {
        if ($stmt->class instanceof PhpParser\Node\Name) {
            if (!in_array($stmt->class->parts[0], ['self', 'static', 'parent'])) {
                $instanceof_class = ClassChecker::getAbsoluteClassFromName($stmt->class, $this->_namespace, $this->_checker->getAliasedClasses());
                return $instanceof_class;

            } elseif ($stmt->class->parts === ['self']) {
                return $this->_absolute_class;
            }
        }

        return null;
    }

    protected function _getVariable(PhpParser\Node\Expr $stmt)
    {
        if ($stmt instanceof PhpParser\Node\Expr\Variable && is_string($stmt->name)) {
            return $stmt->name;
        }
        else if ($stmt instanceof PhpParser\Node\Expr\PropertyFetch &&
                $stmt->var instanceof PhpParser\Node\Expr\Variable &&
                $stmt->var->name === 'this' &&
                is_string($stmt->name)) {
            return $this->_absolute_class . '::' . $stmt->name;
        }

        return null;
    }

    protected static function _hasNullVariable(PhpParser\Node\Expr $conditional)
    {
        if ($conditional->right instanceof PhpParser\Node\Expr\ConstFetch &&
            $conditional->right->name instanceof PhpParser\Node\Name &&
            $conditional->right->name->parts === ['null']) {
            return self::ASSIGNMENT_TO_RIGHT;
        }

        if ($conditional->left instanceof PhpParser\Node\Expr\ConstFetch &&
            $conditional->left->name instanceof PhpParser\Node\Name &&
            $conditional->left->name->parts === ['null']) {
            return self::ASSIGNMENT_TO_LEFT;
        }

        return null;
    }

    protected static function _hasNullCheck(PhpParser\Node\Expr $stmt)
    {
        if ($stmt instanceof PhpParser\Node\Expr\FuncCall && $stmt->name instanceof PhpParser\Node\Name && $stmt->name->parts === ['is_null']) {
            return true;
        }

        return false;
    }

    protected static function _hasArrayCheck(PhpParser\Node\Expr $stmt)
    {
        if ($stmt instanceof PhpParser\Node\Expr\FuncCall && $stmt->name instanceof PhpParser\Node\Name && $stmt->name->parts === ['is_array']) {
            return true;
        }

        return false;
    }

    /**
     * Takes two arrays and consolidates them, removing null values from existing types where applicable
     *
     * @param  array  $new_types
     * @param  array  $existing_types
     * @return array
     */
    public static function reconcileTypes(array $new_types, array $existing_types, $strict, $file_name, $line_number)
    {
        $keys = array_merge(array_keys($new_types), array_keys($existing_types));
        $keys = array_unique($keys);

        $result_types = [];

        if (empty($new_types)) {
            return $existing_types;
        }

        foreach ($keys as $key) {
            $existing_type = isset($existing_types[$key]) && is_string($existing_types[$key]) ? explode('|', $existing_types[$key]) : null;

            if (isset($new_types[$key])) {
                if (is_string($new_types[$key]) && $new_types[$key][0] === '!') {
                    if ($existing_type) {
                        if ($new_types[$key] === '!empty' || $new_types[$key] === '!null') {
                            $null_pos = array_search('null', $existing_type);

                            if ($null_pos !== false) {
                                array_splice($existing_type, $null_pos, 1);

                                if (empty($existing_type)) {
                                    // @todo - I think there's a better way to handle this, but for the moment
                                    // mixed will have to do.
                                    $result_types[$key] = 'mixed';
                                }
                                else {
                                    $result_types[$key] = implode('|', $existing_type);
                                }
                            }
                            else {
                                // if we cannot find a null declaration to remove, just use existing type
                                $result_types[$key] = $existing_types[$key];
                            }
                        }
                        else {
                            $negated_type = substr($new_types[$key], 1);

                            $type_pos = array_search($negated_type, $existing_type);

                            if ($type_pos !== false) {
                                array_splice($existing_type, $type_pos, 1);

                                if (empty($existing_type)) {
                                    if ($strict) {
                                        throw new CodeException('Cannot resolve types for ' . $key, $file_name, $line_number);
                                    }

                                    $result_types[$key] = $existing_types[$key];
                                }
                                $result_types[$key] = implode('|', $existing_type);
                            }
                            else {
                                // if we cannot find a type to negate, just use the existing type
                                $result_types[$key] = $existing_types[$key];
                            }
                        }
                    }
                    else if (isset($existing_types[$key])) {
                        $result_types[$key] = $existing_types[$key];
                    }
                }
                else {
                    $result_types[$key] = $new_types[$key];
                }
            }
            else {
                $result_types[$key] = $existing_types[$key];
            }
        }

        return $result_types;
    }

    public static function negateTypes(array $types)
    {
        return array_map(function ($type) {
            return $type[0] === '!' ? substr($type, 1) : '!' . $type;
        }, $types);
    }

    public static function tokenize($return_type)
    {
        $return_type_tokens = [''];
        $was_char = false;

        foreach (str_split($return_type) as $char) {
            if ($was_char) {
                $return_type_tokens[] = '';
            }

            if ($char === '<' || $char === '>') {
                if ($return_type_tokens[count($return_type_tokens) - 1] === '') {
                    $return_type_tokens[count($return_type_tokens) - 1] = $char;
                }
                else {
                    $return_type_tokens[] = $char;
                }

                $was_char = true;
            }
            else {
                $return_type_tokens[count($return_type_tokens) - 1] .= $char;
                $was_char = false;
            }
        }

        return $return_type_tokens;
    }

    public static function convertSquareBrackets($type)
    {
        return preg_replace_callback(
            '/([a-zA-Z\<\>]+)((\[\])+)/',
            function ($matches) {
                $inner_type = $matches[1];

                $dimensionality = strlen($matches[2]) / 2;

                for ($i = 0; $i < $dimensionality; $i++) {
                    $inner_type = 'array<' . $inner_type . '>';
                }

                return $inner_type;
            },
            $type
        );
    }
}
