<?php

/**
 * This file is part of \Dana\ShellConf.
 *
 * @copyright © dana <https://github.com/okdana>
 * @license   MIT
 */

namespace Dana\Test\ShellConf;

use PHPUnit\Framework\TestCase;
use Dana\ShellConf\ShellConf;

/**
 * Provides tests for \Dana\ShellConf\ShellConf.
 */
class ShellConfTest extends TestCase {
	protected $obj;

	/**
	 * Performs pre-test set-up.
	 *
	 * @return self
	 */
	protected function setUp() {
		$this->obj = new ShellConf();
	}

	/**
	 * Sources a line with bash and outputs the resultant value.
	 *
	 * @param string $line The line to be sourced (usually like FOO=bar).
	 * @param string $name The variable name expected from the line.
	 *
	 * @return string
	 */
	protected function getValueWithBash($line, $name) {
		$tmp = tempnam('/tmp', 'ShellConfTest.');

		file_put_contents($tmp, $line . "\n", \LOCK_EX);

		$cmd = sprintf(
			'bash -c \'unset %s; source %s; printf "%%s\n" "${%s}";\' 2> /dev/null',
			$name,
			escapeshellarg($tmp),
			$name
		);

		$value = rtrim(shell_exec($cmd), "\r\n");

		unlink($tmp);

		return $value;
	}

	/**
	 * Data provider for parseLine() arguments and results.
	 *
	 * @return array[]
	 */
	public function provideParseLineTests() {
		return [
			// Empty and white-space-only lines
			['',     null, null],
			[' ',    null, null],
			["\t",   null, null],
			[" \t ", null, null],

			// Comment-only lines
			['#',         null, null],
			['# ',        null, null],
			['#foo',      null, null],
			['# foo',     null, null],
			['#foo bar',  null, null],
			['# foo bar', null, null],
			['#foo=bar',  null, null],

			// Empty-string values
			['FOO=',   'FOO', ''],
			['FOO=""', 'FOO', ''],
			["FOO=''", 'FOO', ''],

			// One-word values
			['FOO=bar',   'FOO', 'bar'],
			['FOO="bar"', 'FOO', 'bar'],
			["FOO='bar'", 'FOO', 'bar'],

			// Multi-word values
			['FOO=bar baz',   false, false],
			['FOO="bar baz"', 'FOO', 'bar baz'],
			["FOO='bar baz'", 'FOO', 'bar baz'],

			// Special character '$' and escaping
			['FOO=bar$baz',      false, false],
			['FOO=bar\\$baz',    'FOO', 'bar$baz'],
			['FOO="bar$baz"',    false, false],
			['FOO="bar\\$baz"',  'FOO', 'bar$baz'],
			["FOO='bar\$baz'",   'FOO', 'bar$baz'],
			["FOO='bar\\\$baz'", 'FOO', 'bar\\$baz'],

			// Special character '"' and escaping
			['FOO=bar"baz',      false, false],
			['FOO=bar\\"baz',    'FOO', 'bar"baz'],
			['FOO="bar"baz"',    false, false],
			['FOO="bar\\"baz"',  'FOO', 'bar"baz'],
			["FOO='bar\"baz'",   'FOO', 'bar"baz'],
			["FOO='bar\\\"baz'", 'FOO', 'bar\\"baz'],

			// Special character "'" and escaping
			['FOO=bar\'baz',     false, false],
			['FOO=bar\\\'baz',   'FOO', 'bar\'baz'],
			['FOO="bar\'baz"',   'FOO', 'bar\'baz'],
			['FOO="bar\\\'baz"', 'FOO', 'bar\\\'baz'],
			["FOO='bar'baz'",    false, false],
			["FOO='bar\\'baz'",  false, false],

			// Special character '`' and escaping
			['FOO=bar`baz',     false, false],
			['FOO=bar\\`baz',   'FOO', 'bar`baz'],
			['FOO="bar`baz"',   false, false],
			['FOO="bar\\`baz"', 'FOO', 'bar`baz'],
			["FOO='bar`baz'",   'FOO', 'bar`baz'],
			["FOO='bar\\`baz'", 'FOO', 'bar\\`baz'],

			// Special character '\' and escaping
			['FOO=bar\\baz',     'FOO', 'barbaz'],
			['FOO=bar\\\\baz',   'FOO', 'bar\\baz'],
			['FOO="bar\\baz"',   'FOO', 'bar\\baz'],
			['FOO="bar\\\\baz"', 'FOO', 'bar\\baz'],
			["FOO='bar\\baz'",   'FOO', 'bar\\baz'],
			["FOO='bar\\\\baz'", 'FOO', 'bar\\\\baz'],

			// Escape sequences
			['FOO=bar\\nbaz',   'FOO', 'barnbaz'],
			['FOO="bar\\nbaz"', 'FOO', 'bar\\nbaz'],
			["FOO='bar\\nbaz'", 'FOO', 'bar\\nbaz'],
			['FOO=bar\\tbaz',   'FOO', 'bartbaz'],
			['FOO="bar\\tbaz"', 'FOO', 'bar\\tbaz'],
			["FOO='bar\\tbaz'", 'FOO', 'bar\\tbaz'],

			// Multi-byte characters
			['FOO=bar…baz',     'FOO', 'bar…baz'],
			['FOO=bar\\…baz',   'FOO', 'bar…baz'],
			['FOO="bar…baz"',   'FOO', 'bar…baz'],
			['FOO="bar\\…baz"', 'FOO', 'bar\\…baz'],
			["FOO='bar…baz'",   'FOO', 'bar…baz'],
			["FOO='bar\\…baz'", 'FOO', 'bar\\…baz'],

			// Trailing semi-colons
			['FOO=bar;',    'FOO', 'bar'],
			['FOO=bar ;',   'FOO', 'bar'],
			['FOO="bar";',  'FOO', 'bar'],
			['FOO="bar" ;', 'FOO', 'bar'],
			["FOO='bar';",  'FOO', 'bar'],
			["FOO='bar' ;", 'FOO', 'bar'],

			// Trailing comments
			['FOO=bar#baz',    'FOO', 'bar#baz'],
			['FOO=bar #baz',   'FOO', 'bar'],
			['FOO="bar"#baz',  false, false],
			['FOO="bar" #baz', 'FOO', 'bar'],
			["FOO='bar'#baz",  false, false],
			["FOO='bar' #baz", 'FOO', 'bar'],

			// Trailing semi-colons and comments
			['FOO=bar; #baz',    'FOO', 'bar'],
			['FOO=bar ; #baz',   'FOO', 'bar'],
			['FOO="bar"; #baz',  'FOO', 'bar'],
			['FOO="bar" ; #baz', 'FOO', 'bar'],
			["FOO='bar'; #baz",  'FOO', 'bar'],
			["FOO='bar' ; #baz", 'FOO', 'bar'],

			// Key-word prefixes
			// (n.b. we can't test `local` quite properly because bash only
			// allows it within a function)
			['declare FOO=bar', 'FOO', 'bar'],
			['export FOO=bar',  'FOO', 'bar'],
			[' export FOO=bar', 'FOO', 'bar'],
			['export  FOO=bar', 'FOO', 'bar'],

			// Legal variable names
			['FOO1=bar',  'FOO1',  'bar'],
			['FOO_=bar',  'FOO_',  'bar'],
			['_FOO=bar',  '_FOO',  'bar'],
			['__FOO=bar', '__FOO', 'bar'],
			['_foo=bar',  '_foo',  'bar'],
			['_1=bar',    '_1',    'bar'],
			['__=bar',    '__',    'bar'],

			// Illegal variable names
			['1FOO=bar', false, false],
			['1=bar',    false, false],
			['1_=bar',   false, false],
			['_=bar',    false, false],

			// Various other lines that we consider illegal (but bash may not)
			['FOO="bar"\'baz\'', false, false],
			['FOO=$\'bar\'',     false, false],
			['foo',              false, false],
			['foo bar',          false, false],
			['=',                false, false],
			['=foo',             false, false],
		];
	}

	/**
	 * Tests parseLine() method.
	 *
	 * @param string $line
	 * @param string $expectedName
	 * @param mixed  $expectedValue
	 *
	 * @return void
	 *
	 * @dataProvider provideParseLineTests
	 */
	public function testParseLine($line, $expectedName, $expectedValue) {
		// A value of null should represent an empty line or a comment
		try {
			$parsed = $this->obj->parseLine($line);
			$name   = $parsed[0] ?? null;
			$value  = $parsed[1] ?? null;

		// A value of false will represent an exception
		} catch ( \Exception $e ) {
			$name  = false;
			$value = false;
		}

		$this->assertSame($expectedName,  $name);
		$this->assertSame($expectedValue, $value);

		// Also test against bash if it seems applicable
		if ( $value !== null && $value !== false ) {
			$this->assertSame(
				$expectedValue,
				$this->getValueWithBash($line, $name)
			);
		}
	}
}

