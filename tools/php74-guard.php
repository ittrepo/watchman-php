<?php

/*
 * Fails if any file under src/, bin/ or config/ uses syntax or functions newer
 * than PHP 7.4 (ITT runs 7.4 on some servers). Needs nikic/php-parser ^5:
 *
 *   php tools/php74-guard.php [path/to/vendor/autoload.php] [other/app/dir]
 *
 * CI also lints on a real php:7.4 image; this catches it before a push.
 */

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;

require $argv[1] ?? __DIR__.'/../vendor/autoload.php';

$php8Functions = ['str_contains', 'str_starts_with', 'str_ends_with', 'get_debug_type', 'fdiv', 'get_resource_id', 'array_is_list', 'preg_last_error_msg'];

$visitor = new class($php8Functions) extends NodeVisitorAbstract {
    public $problems = [];
    private $functions;

    public function __construct(array $functions) { $this->functions = $functions; }

    public function enterNode(Node $node)
    {
        $why = null;

        if ($node instanceof Node\Expr\Match_) $why = 'match expression (8.0)';
        elseif ($node instanceof Node\Expr\NullsafeMethodCall || $node instanceof Node\Expr\NullsafePropertyFetch) $why = 'nullsafe ?-> (8.0)';
        elseif ($node instanceof Node\AttributeGroup) $why = 'attribute #[...] (8.0)';
        elseif ($node instanceof Node\UnionType || $node instanceof Node\IntersectionType) $why = 'union/intersection type (8.0/8.1)';
        elseif ($node instanceof Node\Param && $node->flags !== 0) $why = 'constructor promotion (8.0)';
        elseif ($node instanceof Node\Arg && $node->name !== null) $why = 'named argument (8.0)';
        elseif ($node instanceof Node\Stmt\Catch_ && $node->var === null) $why = 'catch without variable (8.0)';
        elseif ($node instanceof Node\Stmt\Enum_) $why = 'enum (8.1)';
        elseif ($node instanceof Node\Expr\Throw_ && ! $node->getAttribute('parent') instanceof Node\Stmt\Expression) $why = 'throw expression (8.0)';
        elseif (($node instanceof Node\Identifier || $node instanceof Node\Name) && in_array(strtolower($node instanceof Node\Name ? $node->toString() : $node->name), ['mixed', 'static', 'never', 'null', 'false', 'true'], true)
            && ($node->getAttribute('parent') instanceof Node\FunctionLike || $node->getAttribute('parent') instanceof Node\Param
                || $node->getAttribute('parent') instanceof Node\NullableType || $node->getAttribute('parent') instanceof Node\Stmt\Property)) $why = "type '".strtolower($node instanceof Node\Name ? $node->toString() : $node->name)."' (8.0+)";
        elseif ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name && in_array(strtolower($node->name->toString()), $this->functions, true)) $why = $node->name->toString().'() (8.0+)';
        elseif ($node instanceof Node\Stmt\Property && ($node->flags & Node\Stmt\Class_::MODIFIER_READONLY)) $why = 'readonly (8.1)';
        elseif ($node instanceof Node\VariadicPlaceholder) $why = 'first-class callable (8.1)';

        if ($why !== null) {
            $this->problems[] = [$node->getStartLine(), $why];
        }

        return null;
    }
};

$parser = (new ParserFactory())->createForNewestSupportedVersion();
// Extra arguments: other directories to check (e.g. an app that embeds the SDK).
$extra = array_slice($argv, 2);
$root = $extra === [] ? dirname(__DIR__) : rtrim(realpath($extra[0]) ?: $extra[0], '/');
$dirs = $extra === [] ? ['src', 'bin', 'config', 'tests'] : ['.'];
$files = [];
foreach ($dirs as $dir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (substr($file->getFilename(), -4) === '.php') $files[] = $file->getPathname();
    }
}
sort($files);

$failed = 0;
foreach ($files as $file) {
    $code = file_get_contents($file);
    // Trailing comma in a parameter list is 8.0; php-parser does not flag it.
    if (preg_match('/function\s*[\w&]*\s*\((?:[^()]|\([^()]*\))*,\s*\)/s', $code)) {
        echo "$file: trailing comma in parameter list (8.0)\n";
        $failed++;
    }
    $ast = $parser->parse($code);
    $visitor->problems = [];
    $t = new NodeTraverser();
    $t->addVisitor(new ParentConnectingVisitor());
    $t->addVisitor($visitor);
    $t->traverse($ast);
    foreach ($visitor->problems as [$line, $why]) {
        echo str_replace($root.'/', '', $file).":$line: $why\n";
        $failed++;
    }
}

echo $failed === 0 ? 'PHP 7.4 guard: '.count($files)." files OK\n" : "PHP 7.4 guard: $failed problem(s)\n";
exit($failed === 0 ? 0 : 1);
