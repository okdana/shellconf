<?php

/**
 * This file is part of \Dana\ShellConf.
 *
 * @copyright © dana <https://github.com/okdana>
 * @license   MIT
 */

namespace Dana\ShellConf;

class ShellConf {
	/**
	 * @var string[] Characters we require to be escaped in double-quoted values.
	 *
	 * @see ShellConf::parseLine()
	 * @see POSIX.1-2008 XCU 2.2
	 */
	const DOUBLE_QUOTED_MUST_ESCAPE = [
		'$',
		'`',
		'\\',
		'"',
	];

	/**
	 * @var string[] Characters we require to be escaped in unquoted values.
	 *
	 * @see ShellConf::parseLine()
	 * @see POSIX.1-2008 XCU 2.2
	 */
	const UNQUOTED_MUST_ESCAPE = [
		'|',
		'&',
		';',
		'<',
		'>',
		'(',
		')',
		'$',
		'`',
		'\\',
		'"',
		"'",
		' ',
		"\t",
	];

	/**
	 * @var array The data we've parsed or been given.
	 */
	protected $data = [];

	/**
	 * Loads data from the specified config file.
	 *
	 * @see ShellConf::parse()
	 *
	 * @param string $file The file path to load from.
	 *
	 * @return self
	 *
	 * @throws \InvalidArgumentException if the $file path is invalid.
	 */
	public function load($file) {
		if ( ! is_readable($file) || is_dir($file) ) {
			throw new \InvalidArgumentException(
				"Missing or invalid file path: ${file}"
			);
		}

		return $this->parse(file_get_contents($file));
	}

	/**
	 * Parses data from the supplied config string.
	 *
	 * Note that data parsed by this method will be merged with any data
	 * previously added. To clear existing data, use reset().
	 *
	 * @param string $env A string of new-line-separated variable assignments.
	 *
	 * @return self
	 */
	public function parse($env) {
		$data = [];

		if ( is_string($env) ) {
			$env = explode("\n", $env);
		}

		if ( ! is_array($env) ) {
			throw new \InvalidArgumentException('Expected string or array');
		}

		foreach ( $env as $line ) {
			$parsed = $this->parseLine($line);

			if ( empty($parsed) ) {
				continue;
			}

			$data[$parsed[0]] = $parsed[1];
		}

		$this->data = array_merge($this->data, $data);

		return $this;
	}

	/**
	 * Parses a line from a config file.
	 *
	 * Valid lines include empty strings, strings containing only white space,
	 * strings whose first non-white-space character is a hash, and supported
	 * variable-assignment statements.
	 *
	 * @param string $line The line to parse.
	 *
	 * @return array
	 *   An array whose first member is the name of the variable parsed from the
	 *   line, and whose second member is the value of the variable. If $line is
	 *   legal but contains no variable assignment, an empty array is returned.
	 *
	 * @throws \UnexpectedValueException on any parse error.
	 */
	public function parseLine($line) {
		// Comment or empty line
		if ( $line === '' || preg_match('/^[\\t ]*?(?:\#.*)?$/', $line) ) {
			return [];
		}

		preg_match(
			'/^\s*?(?:(?:declare|export|local)\s+?)?(\S+?)=(.*?)(?:\s*?;)?(\s*?|\s+?\#.*)?$/',
			$line,
			$matches
		);

		if ( empty($matches) ) {
			throw new \UnexpectedValueException(sprintf(
				'Unexpected character in line: %s',
				$line
			));
		}

		$name  = $matches[1];
		$value = $matches[2];

		if ( ! $this->isLegalName($name) ) {
			throw new \UnexpectedValueException(sprintf(
				"Illegal variable name '%s' in line: %s",
				$name,
				$line
			));
		}

		// Empty value
		if ( $value === '' || $value === '""' || $value === "''" ) {
			return [$name, ''];
		}

		// Quoted value
		if ( $value[0] === '"' || $value[0] === "'" ) {
			$startQuote = $value[0];
			$endQuote   = substr($value, -1);
			$value      = substr($value, 1, -1);
		// Unquoted value
		} else {
			$startQuote = null;
			$endQuote   = null;
		}

		// Quotes should be balanced
		if ( $startQuote !== $endQuote ) {
			throw new \UnexpectedValueException(sprintf(
				'Quote mismatch on line: %s',
				$line
			));
		}

		// Single-quoted value — easy because everything is literal
		if ( $startQuote === "'" ) {
			// The value must not contain single-quotes though
			if ( strpos($value, "'") !== false ) {
				throw new \UnexpectedValueException(sprintf(
					'Illegal single-quote in single-quoted value on line: %s',
					$line
				));
			}

			if ( ! $this->isLegalValue($value) ) {
				throw new \UnexpectedValueException(sprintf(
					'Illegal character in value on line: %s',
					$line
				));
			}

			return [$name, $value];
		}

		$newValue = '';
		$escape   = false;

		foreach ( preg_split('//u', $value, -1, \PREG_SPLIT_NO_EMPTY) as $c ) {
			// Back-slash — start escape
			if ( ! $escape && $c === '\\' ) {
				$escape = true;
				continue;
			}

			// Certain special characters must be escaped in double-quotes in
			// bash if they're to be treated literally. Since we don't support
			// functionality like variable expansion here, we'll say that they
			// always need to be escaped — otherwise the variables would have
			// different values here vs in bash. Note that bash does allow some
			// special characters, notably $, to go unescaped if it can deduce
			// that the following character isn't going to trigger expansion;
			// we're going to make it simple here and just be strict about it
			if ( $startQuote === '"' && in_array($c, static::DOUBLE_QUOTED_MUST_ESCAPE, true) ) {
				if ( ! $escape ) {
					throw new \UnexpectedValueException(sprintf(
						'Unescaped special character in double-quoted value on line: %s',
						$line
					));
				}

				$newValue .= $c;
				$escape    = false;
				continue;
			}

			// Unquoted values have some additional characters that need
			// escaped; otherwise our rational is the same as above
			if ( $startQuote === null && in_array($c, static::UNQUOTED_MUST_ESCAPE, true) ) {
				if ( ! $escape ) {
					throw new \UnexpectedValueException(sprintf(
						'Unescaped special character in unquoted value on line: %s',
						$line
					));
				}

				$newValue .= $c;
				$escape    = false;
				continue;
			}

			// Escaped non-special character
			if ( $escape ) {
				// Treat back-slash literally if double-quoted
				if ( $startQuote === '"' ) {
					$newValue .= '\\' . $c;
				// Strip it out back-slash if unquoted
				} else {
					$newValue .= $c;
				}

				$escape = false;
				continue;
			}

			$newValue .= $c;
		}

		if ( ! $this->isLegalValue($newValue) ) {
			throw new \UnexpectedValueException(sprintf(
				'Illegal character in value on line: %s',
				$line
			));
		}

		return [$name, $newValue];
	}

	/**
	 * Resets the data stored within the object.
	 *
	 * @return self
	 */
	public function reset() {
		$this->data = [];
		return $this;
	}

	/**
	 * Sorts the data stored in the object by variable name.
	 *
	 * This is useful primarily when writing back a string representing the
	 * config — sometimes it's desirable to sort the variables a certain way.
	 *
	 * @see \uksort()
	 * @see sortByValue
	 *
	 * @param callable|null $callback
	 *   (optional) A call-back to pass to \uksort(). If null or unset, a
	 *   standard $a <=> $b comparison method is applied, which should sort the
	 *   variables by the lexicographical order of their names.
	 *
	 * @return self
	 *
	 * @throws \RuntimeException if \uksort() fails.
	 */
	public function sortByName($callback = null) {
		$data = $this->data;

		if ( $callback === null ) {
			$callback = function ($a, $b) {
				return $a <=> $b;
			};
		}

		if ( ! uksort($data, $callback) ) {
			throw new \RuntimeException('Call to uksort() failed');
		}

		$this->data = $data;
		return $this;
	}

	/**
	 * Sorts the data stored in the object by variable value.
	 *
	 * This is useful primarily when writing back a string representing the
	 * config — sometimes it's desirable to sort the variables a certain way.
	 *
	 * @see \uasort()
	 * @see sortByName
	 *
	 * @param callable|null $callback
	 *   (optional) A call-back to pass to \uasort(). If null or unset, a
	 *   standard $a <=> $b comparison method is applied, which should sort the
	 *   variables by the lexicographical order of their values.
	 *
	 * @return self
	 *
	 * @throws \RuntimeException if \uasort() fails.
	 */
	public function sortByValue($callback = null) {
		$data = $this->data;

		if ( $callback === null ) {
			$callback = function ($a, $b) {
				return $a <=> $b;
			};
		}

		if ( ! uasort($data, $callback) ) {
			throw new \RuntimeException('Call to uasort() failed');
		}

		$this->data = $data;
		return $this;
	}

	/**
	 * Adds or sets a variable.
	 *
	 * Note that we do not accept carriage returns or line feeds in values.
	 *
	 * @param string $name  The name of the variable to set.
	 * @param string $value The value to set the variable to.
	 *
	 * @return self
	 *
	 * @throws \InvalidArgumentException if $name or $value is illegal.
	 */
	public function set($name, $value) {
		if ( ! $this->isLegalName($name) ) {
			throw new \InvalidArgumentException(sprintf(
				"Illegal variable name '%s'",
				$name
			));
		}

		if ( ! $this->isLegalValue($value) ) {
			throw new \InvalidArgumentException(sprintf(
				"Illegal value '%s'",
				$value
			));
		}

		$this->data[$name] = (string) $value;
		return $this;
	}

	/**
	 * Unsets a variable.
	 *
	 * @param string $name The name of the variable to unset.
	 *
	 * @return self
	 */
	public function unset($name) {
		unset($this->data[$name]);
		return $this;
	}

	/**
	 * Returns the value of a variable.
	 *
	 * @param string $name
	 *   The name of the variable whose value should be returned.
	 *
	 * @param mixed $default
	 *   (optional) The value to return if the variable $name isn't set. The
	 *   default is null. Since variable values must be strings, one can easily
	 *   distinguish between an empty string and an unset variable by using null
	 *   or false here.
	 *
	 * @return self
	 */
	public function get($name, $default = null) {
		return $this->data ?? $default;
	}

	/**
	 * Produces a bash-conforming variable-assignment statement for the given
	 * name/value pair.
	 *
	 * Note that no validation is performed on any of the values provided to
	 * this function — it is assumed that they've been pre-validated.
	 *
	 * @param string $name
	 *   The name of the variable to assign.
	 *
	 * @param string $value
	 *   (optional) The value to assign the variable to.
	 *
	 * @param string $prefix
	 *   (optional) A prefix to add to each line (often 'export').
	 *
	 * @return [type]
	 */
	public function getLine($name, $value = '', $prefix = '') {
		if ( $prefix === '' ) {
			$prefix = null;
		}

		return sprintf(
			'%s%s="%s"',
			$prefix === null ? '' : $prefix . ' ',
			$name,
			$this->doubleQuoteValue($value)
		);
	}

	/**
	 * Returns whether the supplied variable name is legal.
	 *
	 * Note that the variable name '_', whilst technically accepted for
	 * assignment by bash, can not actually be used, so this method treats it as
	 * illegal.
	 *
	 * @param string $name The variable name to test.
	 *
	 * @return bool
	 */
	public function isLegalName($name) {
		// This variable name is special to bash, so we can't use it
		if ( $name === '_' ) {
			return false;
		}
		return (bool) preg_match('/^[_A-Z][A-Za-z0-9_]*$/', $name);
	}

	/**
	 * Returns whether the supplied variable value is legal.
	 *
	 * This function is more or less specific to our implementation.
	 *
	 * @param string $value The variable value to test
	 *
	 * @return bool
	 */
	public function isLegalValue($value) {
		switch ( true ) {
			case strpos($value, "\0") !== false:
			case strpos($value, "\n") !== false:
			case strpos($value, "\r") !== false:
				return false;
		}
		return true;
	}

	/**
	 * Properly escapes and double-quotes a value.
	 *
	 * Note that no special validation is performed on the value.
	 *
	 * @param string $value The value to escape and quote.
	 *
	 * @return string
	 */
	public function doubleQuoteValue($value) {
		$value = addcslashes(
			$value,
			implode('', static::DOUBLE_QUOTED_MUST_ESCAPE)
		);
		return '"' . $value . '"';
	}

	/**
	 * Dumps the object's data as an associative array.
	 *
	 * @return array
	 */
	public function toArray() {
		return $this->data;
	}

	/**
	 * Dumps a string representation of the object's data, suitable for writing
	 * to a file.
	 *
	 * @see ShellConf::getLine()
	 *
	 * @param string $prefix (optional) A line prefix to pass to getLine().
	 *
	 * @return string
	 */
	public function toString($prefix = '') {
		$lines = [];

		foreach ( $this->data as $name => $value ) {
			$lines[] = $this->getLine($name, $value, $prefix);
		}

		return implode("\n", $lines);
	}
}

