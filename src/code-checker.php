<?php

/**
 * Source Codes Checker.
 *
 * This file is part of the Nette Framework (http://nette.org)
 */

use Nette\Utils\Strings,
	Nette\CommandLine\Parser;


set_exception_handler(function($e) {
	echo "Error: {$e->getMessage()}\n";
	die(2);
});


if (@!include __DIR__ . '/../vendor/autoload.php') {
	echo('Install packages using `composer update`');
	exit(1);
}


echo '
CodeChecker version 2.2
-----------------------
';

$cmd = new Parser(<<<XX
Usage:
    php code-checker.php [options]

Options:
    -d <path>  folder to scan (default: current directory)
    -f         fixes files
    -l         convert newline characters


XX
, array(
	'-d' => array(Parser::REALPATH => TRUE, Parser::VALUE => getcwd()),
));

$options = $cmd->parse();
if ($cmd->isEmpty()) {
	$cmd->help();
}



class CodeChecker extends Nette\Object
{
	public $tasks = array();

	public $readOnly = FALSE;

	public $accept = array(
		'*.php', '*.phpt', '*.inc',
		'*.txt', '*.texy', '*.md',
		'*.css', '*.less', '*.js', '*.json', '*.latte', '*.htm', '*.html', '*.phtml', '*.xml',
		'*.ini', '*.neon',
		'*.sh', '*.bat',
		'*.sql',
		'.htaccess', '.gitignore',
	);

	public $ignore = array(
		'.*', '*.tmp', 'tmp', 'temp', 'log', 'vendor',
	);

	private $file;

	private $error;


	public function run($folder)
	{
		set_time_limit(0);

		if ($this->readOnly) {
			echo "Running in read-only mode\n";
		}

		echo "Scanning folder $folder\n";

		$counter = 0;
		$success = TRUE;
		foreach (Nette\Utils\Finder::findFiles($this->accept)->from($folder)->exclude($this->ignore) as $file)
		{
			echo str_pad(str_repeat('.', $counter++ % 40), 40), "\x0D";

			$orig = $s = file_get_contents($file);
			$this->file = ltrim(substr($file, strlen($folder)), '/\\');
			$this->error = FALSE;

			foreach ($this->tasks as $task) {
				$res = $task($this, $s);
				if ($this->error) {
					$success = FALSE;
					continue 2;
				} elseif (is_string($res)) {
					$s = $res;
				}
			}

			if ($s !== $orig && !$this->readOnly) {
				file_put_contents($file, $s);
			}
		}

		echo "\nDone.";
		return $success;
	}


	public function fix($message)
	{
		echo '[' . ($this->readOnly ? 'FOUND' : 'FIX') . "] $this->file   $message\n";
		$this->error = $this->readOnly;
	}


	public function warning($message)
	{
		echo "[WARNING] $this->file   $message\n";
	}


	public function error($message)
	{
		echo "[ERROR] $this->file   $message\n";
		$this->error = TRUE;
	}


	public function is($extensions)
	{
		return in_array(pathinfo($this->file, PATHINFO_EXTENSION), explode(',', $extensions));
	}

}



$checker = new CodeChecker;
$checker->readOnly = !isset($options['-f']);

// control characters checker
$checker->tasks[] = function(CodeChecker $checker, $s) {
	if (Strings::match($s, '#[\x00-\x08\x0B\x0C\x0E-\x1F]#')) {
		$checker->error('contains control characters');
	}
};

// BOM remover
$checker->tasks[] = function(CodeChecker $checker, $s) {
	if (substr($s, 0, 3) === "\xEF\xBB\xBF") {
		$checker->fix('contains BOM');
		return substr($s, 3);
	}
};

// UTF-8 checker
$checker->tasks[] = function(CodeChecker $checker, $s) {
	if (!Strings::checkEncoding($s)) {
		$checker->error('in not valid UTF-8 file');
	}
};

// invalid phpDoc checker
$checker->tasks[] = function(CodeChecker $checker, $s) {
	if ($checker->is('php,phpt')) {
		foreach (token_get_all($s) as $token) {
			if ($token[0] === T_COMMENT && Strings::match($token[1], '#/\*\s.*@[a-z]#isA')) {
				$checker->warning("missing /** in phpDoc comment on line $token[2]");
			}
		}
	}
};

// invalid doublequoted string checker
$checker->tasks[] = function(CodeChecker $checker, $s) {
	if ($checker->is('php,phpt')) {
		$prev = NULL;
		foreach (token_get_all($s) as $token) {
			if (($token[0] === T_ENCAPSED_AND_WHITESPACE && ($prev[0] !== T_START_HEREDOC || !strpos($prev[1], "'")))
				|| ($token[0] === T_CONSTANT_ENCAPSED_STRING && $token[1][0] === '"')
			) {
				$m = Strings::match($token[1], '#^([^\\\\]|\\\\[\\\\nrtvefx0-7\W])*#'); // more strict: '#^([^\\\\]|\\\\[\\\\nrtvef$"x0-7])*#'
				if ($token[1] !== $m[0]) {
					$checker->warning("invalid escape sequence " . substr($token[1], strlen($m[0]), 2) . " in double quoted string on line $token[2]");
				}
			}
			$prev = $token;
		}
	}
};

// newline characters normalizer for the current OS
if (isset($options['-l'])) {
	$checker->tasks[] = function(CodeChecker $checker, $s) {
		$new = str_replace("\n", PHP_EOL, str_replace(array("\r\n", "\r"), "\n", $s));
		if (!$checker->is('sh') && $new !== $s) {
			$checker->fix('contains non-system line-endings');
			return $new;
		}
	};
}

// trailing ? > remover
$checker->tasks[] = function(CodeChecker $checker, $s) {
	if ($checker->is('php,phpt')) {
		$tmp = rtrim($s);
		if (substr($tmp, -2) === '?>') {
			$checker->fix('contains closing PHP tag ?>');
			return substr($tmp, 0, -2);
		}
	}
};

// lint Latte templates
$checker->tasks[] = function(CodeChecker $checker, $s) {
	if ($checker->is('latte')) {
		try {
			$latte = new Latte\Engine;
			$latte->setLoader(new Latte\Loaders\StringLoader);
			$latte->compile($s);
		} catch (Latte\CompileException $e) {
			if (!preg_match('#Unknown (macro|attribute)#A', $e->getMessage())) {
				$checker->error($e->getMessage() . ($e->sourceLine ? " on line $e->sourceLine" : ''));
			}
		}
	}
};

// lint Neon
$checker->tasks[] = function(CodeChecker $checker, $s) {
	if ($checker->is('neon')) {
		try {
			Nette\Neon\Neon::decode($s);
		} catch (Nette\Neon\Exception $e) {
			$checker->error($e->getMessage());
		}
	}
};

// white-space remover
$checker->tasks[] = function(CodeChecker $checker, $s) {
	$new = Strings::replace($s, '#[\t ]+(\r?\n)#', '$1'); // right trim
	$eol = preg_match('#\r?\n#', $new, $m) ? $m[0] : PHP_EOL;
	$new = rtrim($new); // trailing trim
	if ($new !== '') {
		$new .= $eol;
	}
	if ($new !== $s) {
		$bytes = strlen($s) - strlen($new);
		$checker->fix("$bytes bytes of whitespaces");
		return $new;
	}
};

// indentation and tabs checker
$checker->tasks[] = function(CodeChecker $checker, $s) {
	if ($checker->is('php,phpt,css,less,js,json,neon') && strpos($s, "\t") !== FALSE) {
		if ($checker->is('php,phpt')) { // remove spaces from strings
			$res = '';
			foreach (token_get_all($s) as $token) {
				if (is_array($token) && in_array($token[0], array(T_ENCAPSED_AND_WHITESPACE, T_CONSTANT_ENCAPSED_STRING))) {
					$token[1] = preg_replace('#\s#', '', $token[1]);
				}
				$res .= is_array($token) ? $token[1] : $token;
			}
			$s = $res;
		}
		if (preg_match('#(.*)^\t*\ (?!\*)#mAsU', $s, $m)) {
			$checker->error('Mixed tabs and spaces indentation on line ' . (substr_count($m[1], "\n") + 1) . '.');
		}
		if (preg_match('#(.*)\S\ *\t#AsU', $s, $m)) {
			$checker->error('Tabulator found on line ' . (substr_count($m[1], "\n") + 1) . '.');
		}
	}
};

$ok = $checker->run($options['-d']);

exit($ok ? 0 : 1);
