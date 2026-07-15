<?php
/**
 * Small arithmetic DSL for calculated fields; never evaluates PHP or SQL.
 */

namespace EIT\Entry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SafeExpression {

	const MAX_LENGTH = 500;
	const MAX_TOKENS = 100;

	public function evaluate( $expression, array $values ) {
		$tokens = $this->tokenize( $expression );
		if ( is_wp_error( $tokens ) ) {
			return $tokens;
		}
		$postfix = $this->postfix( $tokens );
		return is_wp_error( $postfix ) ? $postfix : $this->calculate( $postfix, $values );
	}

	public function validate( $expression ) {
		$tokens = $this->tokenize( $expression );
		if ( is_wp_error( $tokens ) ) {
			return $tokens;
		}
		$postfix = $this->postfix( $tokens );
		return is_wp_error( $postfix ) ? $postfix : true;
	}

	public function references( $expression ) {
		$tokens = $this->tokenize( $expression );
		if ( is_wp_error( $tokens ) ) {
			return $tokens;
		}
		$references = [];
		foreach ( $tokens as $token ) {
			if ( is_array( $token ) ) {
				$references[] = $token['field'];
			}
		}
		return array_values( array_unique( $references ) );
	}

	private function tokenize( $expression ) {
		$expression = trim( (string) $expression );
		if ( '' === $expression || strlen( $expression ) > self::MAX_LENGTH ) {
			return $this->error( 'eit_entry_expression_length', 'Calculated expression is empty or exceeds 500 characters.' );
		}
		$tokens = [];
		$offset = 0;
		$length = strlen( $expression );
		while ( $offset < $length ) {
			if ( ! preg_match( '/\G\s*(\{([a-f0-9-]{36})\}|(?:\d+(?:\.\d+)?|\.\d+)|[()+\-*\/])\s*/i', $expression, $match, 0, $offset ) ) {
				return $this->error( 'eit_entry_expression_token', 'Calculated expression contains an unsupported token.' );
			}
			$tokens[] = isset( $match[2] ) && '' !== $match[2] ? [ 'field' => strtolower( $match[2] ) ] : $match[1];
			$offset += strlen( $match[0] );
			if ( count( $tokens ) > self::MAX_TOKENS ) {
				return $this->error( 'eit_entry_expression_complexity', 'Calculated expression exceeds the 100-token limit.' );
			}
		}
		return $tokens;
	}

	private function postfix( array $tokens ) {
		$output = [];
		$operators = [];
		$precedence = [ '+' => 1, '-' => 1, '*' => 2, '/' => 2 ];
		$expects_value = true;
		foreach ( $tokens as $token ) {
			if ( is_array( $token ) || is_numeric( $token ) ) {
				if ( ! $expects_value ) {
					return $this->error( 'eit_entry_expression_sequence', 'Calculated expression is missing an operator.' );
				}
				$output[] = $token;
				$expects_value = false;
			} elseif ( '(' === $token ) {
				if ( ! $expects_value ) {
					return $this->error( 'eit_entry_expression_sequence', 'Calculated expression is missing an operator before a parenthesis.' );
				}
				$operators[] = $token;
			} elseif ( ')' === $token ) {
				if ( $expects_value || ! $this->close_parenthesis( $operators, $output ) ) {
					return $this->error( 'eit_entry_expression_parentheses', 'Calculated expression has unbalanced parentheses.' );
				}
				$expects_value = false;
			} elseif ( isset( $precedence[ $token ] ) ) {
				if ( $expects_value ) {
					return $this->error( 'eit_entry_expression_sequence', 'Calculated expression has an operator without a value.' );
				}
				while ( $operators && isset( $precedence[ end( $operators ) ] ) && $precedence[ end( $operators ) ] >= $precedence[ $token ] ) {
					$output[] = array_pop( $operators );
				}
				$operators[] = $token;
				$expects_value = true;
			}
		}
		if ( $expects_value ) {
			return $this->error( 'eit_entry_expression_sequence', 'Calculated expression ends before a value.' );
		}
		while ( $operators ) {
			$operator = array_pop( $operators );
			if ( '(' === $operator ) {
				return $this->error( 'eit_entry_expression_parentheses', 'Calculated expression has unbalanced parentheses.' );
			}
			$output[] = $operator;
		}
		return $output;
	}

	private function close_parenthesis( array &$operators, array &$output ) {
		while ( $operators ) {
			$operator = array_pop( $operators );
			if ( '(' === $operator ) {
				return true;
			}
			$output[] = $operator;
		}
		return false;
	}

	private function calculate( array $tokens, array $values ) {
		$stack = [];
		foreach ( $tokens as $token ) {
			if ( is_array( $token ) ) {
				$value = $values[ $token['field'] ] ?? null;
				if ( is_array( $value ) && isset( $value['amount'] ) ) {
					$value = $value['amount'];
				}
				if ( ! is_numeric( $value ) ) {
					return $this->error( 'eit_entry_expression_field', 'Calculated expression references a non-numeric field.' );
				}
				$stack[] = (float) $value;
			} elseif ( is_numeric( $token ) ) {
				$stack[] = (float) $token;
			} else {
				$right = array_pop( $stack );
				$left = array_pop( $stack );
				if ( null === $left || null === $right || ( '/' === $token && 0.0 === (float) $right ) ) {
					return $this->error( 'eit_entry_expression_math', 'Calculated expression could not be evaluated safely.' );
				}
				$stack[] = $this->operation( $token, $left, $right );
			}
		}
		return 1 === count( $stack ) && is_finite( $stack[0] ) ? $stack[0] : $this->error( 'eit_entry_expression_result', 'Calculated expression did not produce one finite number.' );
	}

	private function operation( $operator, $left, $right ) {
		if ( '+' === $operator ) {
			return $left + $right;
		}
		if ( '-' === $operator ) {
			return $left - $right;
		}
		return '*' === $operator ? $left * $right : $left / $right;
	}

	private function error( $code, $message ) {
		return new \WP_Error( $code, __( $message, 'elementor-implementation-toolkit' ) ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
	}
}
