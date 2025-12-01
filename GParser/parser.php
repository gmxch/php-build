<?php

/* PHP PARSER DEOBFUSCATOR. 
        PHP PARSER powered by nikic\PhpParser
        using parser v4^
        usage <file> <mode>
        see result demo
 */
 
if (file_exists('temp_input.php')) {
    unlink('temp_input.php');}
require "vendor/autoload.php";
ini_set("memory_limit", -1);

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\ParserFactory;
use PhpParser\NodeVisitorAbstract;
use PhpParser\NodeTraverser;
use PhpParser\PrettyPrinter;
use PhpParser\NodeVisitor\ParentConnectingVisitor;

/*
 * MAIN PARSER FUNCTION
 */

function _eval($file) {
/** extractor/cleaner for `eval()`
 * e.g -> eval('return \"eval(\\\"return \\\\\\"echo 123\\\\\\";\\\")\";');
 * e.x -> 'echo 123'
 * @param string $file 
 * @return void
 */
    //$known = _simply($file, false);
    $code = file_get_contents($file);
    $parser = (new ParserFactory)->create(ParserFactory::PREFER_PHP7);
    try { /* AST Modifier */
        $stmts = $parser->parse($code);
    } catch (Error $e) {
        echo "Parse error: " . $e->getMessage();
        return;
    } 
    $traverser = new NodeTraverser();
    $traverser->addVisitor(new ParentConnectingVisitor());
    $traverser->addVisitor(new class($parser, $known) extends NodeVisitorAbstract {
        private $parser;
        private $known; 
        public function __construct($parser, &$knownRef = null) {
            $this->parser = $parser;
            if (is_array($knownRef)) {
                $this->known =& $knownRef;
            } else {
                $this->known = null;
            }
        } /* Direct String */
        private function resolveNodeToString(Node $node) {
            if ($node instanceof Node\Scalar\String_) {
                return $node->value;
            }
            if ($node instanceof Node\Expr\BinaryOp\Concat) {
                $l = $this->resolveNodeToString($node->left);
                $r = $this->resolveNodeToString($node->right);
                if ($l !== null && $r !== null) return $l . $r;
                return null;
            }
            if ($node instanceof Node\Expr\Variable && is_string($node->name)) {
                if (is_array($this->known) && isset($this->known[$node->name])) {
                    return $this->known[$node->name];
                }
                return null;
            }
            if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name) {
                $fname = strtolower($node->name->toString());
                if ($fname === 'base64_decode' && isset($node->args[0])) {
                    $arg = $node->args[0]->value;
                    $v = $this->resolveNodeToString($arg);
                    if ($v !== null) return base64_decode($v);
                }
            }
            if ($node instanceof Node\Scalar\Encapsed) {
                $out = '';
                $parts = $node->parts;
                $count = count($parts);
                for ($i = 0; $i < $count; $i++) {
                    $p = $parts[$i];
                    if ($p instanceof Node\Scalar\EncapsedStringPart) {
                        $val = $p->value;
                        if ($i+1 < $count && substr($val, -1) === '{' && $parts[$i+1] instanceof Node\Expr\Variable) {
                            $val = substr($val, 0, -1);
                        }
                        if ($i-1 >= 0 && substr($val, 0, 1) === '}' && $parts[$i-1] instanceof Node\Expr\Variable) {
                            $val = substr($val, 1);
                        }
                        $out .= $val;
                    } else {
                        $part = $this->resolveNodeToString($p);
                        if ($part === null) return null;
                        $out .= $part;
                    }
                }
                return $out;
            }
            return null;
        } /* modifier Expr to Literal */
        private function evalExprToString(Node\Expr $expr) {
            if ($expr instanceof Node\Scalar\String_) return $expr->value;
            if ($expr instanceof Node\Expr\BinaryOp\Concat) {
                $l = $this->evalExprToString($expr->left);
                $r = $this->evalExprToString($expr->right);
                if ($l !== null && $r !== null) return $l . $r;
                return null;
            }
            if ($expr instanceof Node\Expr\FuncCall && $expr->name instanceof Node\Name) {
                $fname = strtolower($expr->name->toString());
                if ($fname === 'base64_decode' && isset($expr->args[0])) {
                    $arg = $expr->args[0]->value;
                    $av = $this->evalExprToString($arg);
                    if ($av !== null) return base64_decode($av);
                }
            }
            if ($expr instanceof Node\Expr\Variable && is_string($expr->name)) {
                if (is_array($this->known) && isset($this->known[$expr->name])) {
                    return $this->known[$expr->name];
                }
                return null;
            }
            return null;
        } /* Eval inner Modifier */
        private function tryParseInnerReturn($codeString, $depth = 0, $maxDepth = 5) {
            if ($depth >= $maxDepth) return null;
            try {
                $stmts = $this->parser->parse('<?php ' . $codeString);
            } catch (Error $e) {
                $eval = stripcslashes($codeString);
                try {
                    $stmts = $this->parser->parse('<?php ' . $eval);
                    $codeString = $eval;
                } catch (Error $e2) {
                    return null;
                }
            }
            if (!is_array($stmts) || count($stmts) === 0) return null;
            foreach ($stmts as $st) {
                if ($st instanceof Node\Stmt\Return_ && $st->expr instanceof Node\Expr) {
                    $val = $this->evalExprToString($st->expr);
                    if ($val !== null) {
                        $next = $this->tryParseInnerReturn($val, $depth + 1, $maxDepth);
                        return $next ?? $val;
                    }
                }
            }
            return null;
        } /* EvalNode Deobfuscator  */
        public function enterNode(Node $node) {
            if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name) {
                if (strtolower($node->name->toString()) === 'eval' && isset($node->args[0])) {
                    $argNode = $node->args[0]->value;
                    $code = $this->resolveNodeToString($argNode);
                    if ($code !== null) {
                        $ret = $this->tryParseInnerReturn($code);
                        if ($ret !== null) {
                            return new Node\Scalar\String_(ansi_whitelist($ret));
                        }
                    }
                }
            }
            if ($node instanceof Node\Expr\Eval_ && $node->expr !== null) {
                $code = $this->resolveNodeToString($node->expr);
                if ($code !== null) {
                    $ret = $this->tryParseInnerReturn($code);
                    if ($ret !== null) {
                        return new Node\Scalar\String_(ansi_whitelist($ret));
                    }
                }
            }
            return null;
        }
    }); /* AST Traversal Result */ 
    $stmts = $traverser->traverse($stmts);
    $pretty = new PrettyPrinter\Standard;
    echo $pretty->prettyPrintFile($stmts);
}

function _simply($file, $print /* As Default */ = true) {
/** AST Simplifier array code
 * e.g -> $a = "ht", $b = "tp", $c = "{$a},{$b}" 
 * e.x -> $c = http
 * @param string $file 
 * @param bool $print = true (As Default)
 * @return array 
 */
    $code = file_get_contents($file);
    $parser = (new ParserFactory)->create(ParserFactory::PREFER_PHP7);
    $pretty = new PrettyPrinter\Standard;
    try { /* AST Parser */
        $stmts = $parser->parse($code);
    } catch (Throwable $e) {
        echo "Parse error: " . $e->getMessage() . PHP_EOL;
        return;
    }
    $known = [];
    $collector = new class($known) extends NodeVisitorAbstract {
        private $known;
        public function __construct(&$knownRef) { $this->known =& $knownRef; }
        private function resolveNode(Node $node) { /* String Literal */
            if ($node instanceof Node\Scalar\String_) return $node->value;
            if ($node instanceof Node\Expr\BinaryOp\Concat) {
                $left = $this->resolveNode($node->left);
                $right = $this->resolveNode($node->right);
                if ($left !== null && $right !== null) return $left . $right;
            }
            if ($node instanceof Node\Expr\FuncCall &&
                $node->name instanceof Node\Name &&
                strtolower($node->name->toString()) === 'base64_decode' &&
                isset($node->args[0])) {
                $arg = $this->resolveNode($node->args[0]->value);
                if ($arg !== null) return base64_decode($arg);
            }
            if ($node instanceof Node\Expr\Variable && is_string($node->name)) {
                if (isset($this->known[$node->name])) return $this->known[$node->name];
            }
            if ($node instanceof Node\Scalar\Encapsed) {
                $out = '';
                $parts = $node->parts;
                $count = count($parts);
                for ($i = 0; $i < $count; $i++) {
                    $part = $parts[$i];
                    if ($part instanceof Node\Scalar\EncapsedStringPart) {
                        $val = $part->value;
                        if ($i+1 < $count && substr($val, -1) === '{' && $parts[$i+1] instanceof Node\Expr\Variable) {
                            $val = substr($val, 0, -1);
                        }
                        if ($i-1 >= 0 && substr($val, 0, 1) === '}' && $parts[$i-1] instanceof Node\Expr\Variable) {
                            $val = substr($val, 1);
                        }
                        $out .= $val;
                    } elseif ($part instanceof Node\Expr\Variable) {
                        $varName = $part->name;
                        if (isset($this->known[$varName])) $out .= $this->known[$varName];
                        else return null;
                    } else {
                        return null; 
                    }
                }
                return $out;
            }
            return null;
        }
        public function enterNode(Node $node) {
            if ($node instanceof Node\Expr\Assign &&
                $node->var instanceof Node\Expr\Variable &&
                is_string($node->var->name)) {
                $val = $this->resolveNode($node->expr);
                if ($val !== null) $this->known[$node->var->name] = $val;
            }
        }
    };
    $tr = new NodeTraverser();
    $tr->addVisitor(new ParentConnectingVisitor());
    $tr->addVisitor($collector);
    $tr->traverse($stmts);
    $replacer = new class($known) extends NodeVisitorAbstract {
        private $known;
        public function __construct($known) { $this->known = $known; }
        private function resolveEncapsed(Node\Scalar\Encapsed $node) {
            $out = '';
            $parts = $node->parts;
            $count = count($parts);
            for ($i = 0; $i < $count; $i++) {
                $part = $parts[$i];
                if ($part instanceof Node\Scalar\EncapsedStringPart) {
                    $val = $part->value;
                    if ($i+1 < $count && substr($val, -1) === '{' && $parts[$i+1] instanceof Node\Expr\Variable) {
                        $val = substr($val, 0, -1);
                    }
                    if ($i-1 >= 0 && substr($val, 0, 1) === '}' && $parts[$i-1] instanceof Node\Expr\Variable) {
                        $val = substr($val, 1);
                    }
                    $out .= $val;
                } elseif ($part instanceof Node\Expr\Variable) {
                    $varName = $part->name;
                    if (isset($this->known[$varName])) {
                        $out .= $this->known[$varName];
                    } else {
                        return null; 
                    }
                } else {
                    return null;
                }
            }
            return $out;
        } /* Deobfuscator Node and string as literal */
        public function enterNode(Node $node) {
            if ($node instanceof Node\Expr\Variable &&
                is_string($node->name) &&
                isset($this->known[$node->name])) {
                $parent = $node->getAttribute('parent');
                if (!($parent instanceof Node\Expr\Assign && $parent->var === $node)) {
                    //return new Node\Scalar\String_($this->known[$node->name]);
                    return new Node\Scalar\String_(ansi_whitelist($this->known[$node->name]));
                }
            }
            if ($node instanceof Node\Scalar\Encapsed) {
                $res = $this->resolveEncapsed($node);
                //if ($res !== null) return new Node\Scalar\String_($res);
                if ($res !== null) return new Node\Scalar\String_(ansi_whitelist($res));
            }
        }
    }; /* Deobfuscator Vars as literal */
    $tr2 = new NodeTraverser();
    $tr2->addVisitor(new ParentConnectingVisitor());
    $tr2->addVisitor($replacer);
    $stmts = $tr2->traverse($stmts); /* AST Parser Result */
    if ($print) echo $pretty->prettyPrintFile($stmts);
    return $known;
}

function _literal($file) {
/** AST CHANGER code 
 * e.g -> $123123 = "number" 
 * e.x -> $number
 * @param string $file
 * @return void
 */
    $symbolMap = [
        '/' => 'slash', 
        '\\' => 'backslash', 
        '.' => 'dot',
        '-' => 'dash',
        ':' => 'colon', 
        ';' => 'semi',
        ',' => 'comma', 
        '@' => 'at',
        '+' => 'plus', 
        '*' => 'star', 
        '&' => 'and', 
        '%' => 'percent',
        '$' => 'dollar', 
        '#' => 'hash',
        '!' => 'bang', 
        '?' => 'qmark',
        '=' => 'eq', 
        '(' => 'lparen', 
        ')' => 'rparen', 
        '[' => 'lbrack',
        ']' => 'rbrack', 
        '{' => 'lbrace', 
        '}' => 'rbrace', 
        '|' => 'pipe',
        '~' => 'tilde', 
        '`' => 'backtick', 
        '^' => 'caret', 
        '"' => 'quote',
        "'" => 'apos', 
        ' ' => 'space'
    ];
    $code = file_get_contents($file);
    $parser = (new ParserFactory)->create(ParserFactory::PREFER_PHP7);
    $pretty = new PrettyPrinter\Standard;
    try { /* AST Parser */
        $stmts = $parser->parse($code);
    } catch (Throwable $e) {
        echo "Parse error: " . $e->getMessage() . PHP_EOL;
        return;
    }
    $known = [];
    $collector = new class($symbolMap, $known) extends NodeVisitorAbstract {
    private $symbolMap;
    private $knownRef;
    public function __construct($symbolMap, &$knownRef) {
        $this->symbolMap = $symbolMap;
        $this->knownRef =& $knownRef; 
    }
        private function resolveNode(Node $node) {
            if ($node instanceof Node\Scalar\String_) return $node->value;
            if ($node instanceof Node\Expr\FuncCall &&
                $node->name instanceof Node\Name &&
                $node->name->toString() === 'base64_decode' &&
                isset($node->args[0]) &&
                $node->args[0]->value instanceof Node\Scalar\String_) {
                return base64_decode($node->args[0]->value->value);
            }
            return null;
        }
        private function sanitizeVarName($val) {
            $name = '';
            $chars = str_split($val);
            foreach ($chars as $c) {
                if (ctype_alnum($c) || $c === '_') $name .= $c;
                elseif (isset($this->symbolMap[$c])) $name .= '_' . $this->symbolMap[$c];
                else $name .= '_';
            }
            if (ctype_digit(substr($name,0,1))) $name = '_' . $name;
            if (!$name) $name = '_var'.$this->varCounter++;
            return $name;
        }
        public function enterNode(Node $node) {
            if ($node instanceof Node\Expr\Assign &&
                $node->var instanceof Node\Expr\Variable &&
                is_string($node->var->name)) {
                $val = $this->resolveNode($node->expr);
                if ($val !== null) {
                    $safeVar = $this->sanitizeVarName($val);
                    $this->knownRef[$node->var->name] = ['safe'=>$safeVar, 'value'=>$val];
                }
            }
        }
    };
    $tr = new NodeTraverser();
    $tr->addVisitor($collector);
    $tr->traverse($stmts);
    $replacer = new class($known) extends NodeVisitorAbstract {
        private $known;
        public function __construct($known) { $this->known = $known; }
        public function enterNode(Node $node) {
            if ($node instanceof Node\Expr\Variable &&
                is_string($node->name) &&
                isset($this->known[$node->name])) {
                return new Node\Expr\Variable($this->known[$node->name]['safe']);
            }
            return null;
        }
    }; /* Deobfuscator Vars as Declared */ 
    $trB = new NodeTraverser();
    $trB->addVisitor($replacer);
    $stmts = $trB->traverse($stmts);
    $decl = ""; 
    foreach ($known as $orig => $info) {
        $decl .= "\$" . $info['safe'] . " = " . var_export($info['value'], true) . ";\n";
    } /* AST Traversal Result */ 
    echo $decl . "\n" . $pretty->prettyPrintFile($stmts);
}

function _globals(string $file) {
/** global vars CHANGER code
 * e.g -> GLOBALS[key]
 * e.x -> $g_key
 * @param string $file
 * @return void
 */
    $code = file_get_contents($file);
    $parser = (new ParserFactory)->create(ParserFactory::PREFER_PHP7);
    $pretty = new PrettyPrinter\Standard;
    try { /* AST Parser */
        $stmts = $parser->parse($code);
    } catch (Throwable $e) {
        echo "Parse error: " . $e->getMessage() . PHP_EOL; return;
    }
    $resolveNode = function(Node $node) use (&$resolveNode) {
        if ($node instanceof Node\Scalar\String_) return $node->value;
        if ($node instanceof Node\Expr\BinaryOp\Concat) {
            $l = $resolveNode($node->left);
            $r = $resolveNode($node->right);
            if ($l !== null && $r !== null) return $l . $r;
            return null;
        }
        if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name) {
            $fname = strtolower($node->name->toString());
            if ($fname === 'base64_decode' && isset($node->args[0])) {
                $arg = $node->args[0]->value;
                $av = $resolveNode($arg);
                if ($av !== null) return base64_decode($av);
            }
        }
        if ($node instanceof Node\Scalar\Encapsed) {
            $out = '';
            foreach ($node->parts as $p) {
                if ($p instanceof Node\Scalar\EncapsedStringPart) {
                    $out .= $p->value;
                } elseif ($p instanceof Node\Expr\ArrayDimFetch) {
                    if ($p->var instanceof Node\Expr\Variable && is_string($p->var->name) && $p->var->name === 'GLOBALS' && $p->dim instanceof Node\Scalar\String_) {
                        return null;
                    } else {
                        return null;
                    }
                } else {
                    return null;
                }
            }
            return $out;
        }
        if ($node instanceof Node\Expr\ArrayDimFetch &&
            $node->var instanceof Node\Expr\Variable &&
            is_string($node->var->name) &&
            $node->var->name === 'GLOBALS' &&
            $node->dim instanceof Node\Scalar\String_) {
            return null;
        }
        return null;
    };
    $sanitizeKey = function(string $key) {
        $name = preg_replace('/[^A-Za-z0-9_]/', '_', $key);
        if ($name === '') $name = 'g_var';
        if (ctype_digit(substr($name,0,1))) $name = 'g_' . $name;
        return 'g_' . $name;
    };
    $knownGlobals = []; 
    $tr1 = new NodeTraverser();
    $tr1->addVisitor(new ParentConnectingVisitor());
    $tr1->addVisitor(new class($resolveNode, $knownGlobals) extends NodeVisitorAbstract {
        private $resolve;
        public $knownGlobals;
        public function __construct($resolve,array &$kg) { $this->resolve = $resolve; $this->knownGlobals =& $kg; }
        public function enterNode(Node $node) {
            if ($node instanceof Node\Expr\Assign &&
                $node->var instanceof Node\Expr\ArrayDimFetch &&
                $node->var->var instanceof Node\Expr\Variable &&
                is_string($node->var->var->name) &&
                $node->var->var->name === 'GLOBALS' &&
                $node->var->dim instanceof Node\Scalar\String_) {
                $k = $node->var->dim->value;
                $v = call_user_func($this->resolve, $node->expr);
                if ($v !== null) {
                    $this->knownGlobals[$k] = $v;
                }
            }
        }
    });
    $tr1->traverse($stmts); /* Modifier GLOBALS[key] to literal */
    $replacer = new class($knownGlobals, $sanitizeKey) extends NodeVisitorAbstract {
        private $knownGlobals;
        private $sanitizeKey;
        public function __construct($kg, $sanitize) { $this->knownGlobals = $kg; $this->sanitizeKey = $sanitize; }
        private function getGlobalValueIfKnown(Node\Expr\ArrayDimFetch $n) {
            if ($n->var instanceof Node\Expr\Variable && $n->var->name === 'GLOBALS' && $n->dim instanceof Node\Scalar\String_) {
                $k = $n->dim->value;
                if (isset($this->knownGlobals[$k])) return $this->knownGlobals[$k];
            }
            return null;
        } /* GLOBALS inner Modifier */
        public function enterNode(Node $node) {
            if ($node instanceof Node\Expr\ArrayDimFetch &&
                $node->var instanceof Node\Expr\Variable &&
                is_string($node->var->name) &&
                $node->var->name === 'GLOBALS' &&
                $node->dim instanceof Node\Scalar\String_) {
                $k = $node->dim->value;
                if (isset($this->knownGlobals[$k])) {
                    $safe = call_user_func($this->sanitizeKey, $k);
                    return new Node\Expr\Variable($safe);
                }
            } /* optional Parameter handle on define used to GLOBALS */
            if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name) {
                if (strtolower($node->name->toString()) === 'define' && isset($node->args[0]) && isset($node->args[1])) {
                    $nameNode = $node->args[0]->value;
                    $valNode = $node->args[1]->value;
                    $tryResolve = function($n) {
                        if ($n instanceof Node\Scalar\String_) return $n->value;
                        if ($n instanceof Node\Scalar\Encapsed) {
                            $out = '';
                            foreach ($n->parts as $p) {
                                if ($p instanceof Node\Scalar\EncapsedStringPart) { $out .= $p->value; 
                                    continue; 
                                }
                                if ($p instanceof Node\Expr\ArrayDimFetch) {
                                    if ($p->var instanceof Node\Expr\Variable && $p->var->name === 'GLOBALS' && $p->dim instanceof Node\Scalar\String_) {
                                        return null;
                                    } else return null;
                                } else return null;
                            }
                            return $out;
                        }
                        return null;
                    };
                    if ($nameNode instanceof Node\Scalar\String_ && $valNode instanceof Node\Scalar\String_) {
                        return null;
                    }
                }
            }
            return null;
        }
    };
    $tr2 = new NodeTraverser();
    $tr2->addVisitor(new ParentConnectingVisitor());
    $tr2->addVisitor($replacer);
    $stmts2 = $tr2->traverse($stmts);
    $decl = ""; /* Deobfuscator GLOBALS[] as Declared */
    foreach ($knownGlobals as $k => $v) {
        $safe = $sanitizeKey($k);
        $v_safe = ansi_whitelist($v);
        $decl .= '$' . $safe . ' = ' . var_export($v_safe, true) . ";\n";
    } /* AST Parser Result */
    echo $decl . "\n" . $pretty->prettyPrintFile($stmts2);
}

function _goto(string $file) {
/** Goto label Simplifier code
 * e.g -> goto 123, 123:, goto 321, 321: echo('hello')
 * e.x -> echo('hello')
 * @param string $file 
 * @return void
 */
    $code = file_get_contents($file);
    $parser = (new ParserFactory)->create(ParserFactory::PREFER_PHP7);
    $pretty = new PrettyPrinter\Standard;
    try { /* AST Parser */
        $stmts = $parser->parse($code);
    } catch (Throwable $e) {
        echo "Parse error: " . $e->getMessage();
        return;
    } 
    $labelMap = [];
    foreach ($stmts as $i => $stmt) {
        if ($stmt instanceof Node\Stmt\Label) {
            $labelMap[$stmt->name->toString()] = $i;
        }
    } /* Goto simulation */
    $result = [];
    $visited = [];
    $i = 0;
    while ($i < count($stmts)) {
        if (isset($visited[$i])) break;
        $visited[$i] = true;
        $stmt = $stmts[$i];
        if ($stmt instanceof Node\Stmt\Goto_) {
            $label = $stmt->name->toString();
            if (isset($labelMap[$label])) {
                $i = $labelMap[$label];
                continue;
            }
        } /* Label Modifier */
        if (!($stmt instanceof Node\Stmt\Label)) {
            $result[] = $stmt;
        }

        $i++;
    } /* AST Parser Result */
    echo $pretty->prettyPrintFile($result);
}

function _pars($file) { /* beautify Parser */
    $code = file_get_contents($file);
    $prettyPrinter = new PrettyPrinter\Standard;
    $parser = (new ParserFactory)->create(ParserFactory::PREFER_PHP7);
    $p = $parser->parse($code);
    echo str_replace("    ","\t",$prettyPrinter->prettyPrint($p));
}

function _hex($file) {
    $code = file_get_contents($file);
    $parser = (new ParserFactory)->create(ParserFactory::PREFER_PHP7);
    try {
        $stmts = $parser->parse($code);
    } catch (Error $e) {
        echo "Parse error: " . $e->getMessage();
        return;
    }

    $traverser = new NodeTraverser();
    $traverser->addVisitor(new class extends NodeVisitorAbstract {
        private function resolve(Node $node) {
            if ($node instanceof Node\Scalar\String_) {
                return decode_escape($node->value);
            }

            if ($node instanceof Node\Expr\BinaryOp\Concat) {
                $l = $this->resolve($node->left);
                $r = $this->resolve($node->right);
                if ($l !== null && $r !== null) return decode_escape($l . $r);
                return null;
            }

            if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name) {
                $fname = strtolower($node->name->toString());
                $args = $node->args;

                if ($fname === 'chr' && isset($args[0])) {
                    $val = $args[0]->value;
                    if ($val instanceof Node\Scalar\LNumber) {
                        return decode_escape(chr($val->value));
                    }
                }

                if ($fname === 'hex2bin' && isset($args[0])) {
                    $v = $this->resolve($args[0]->value);
                    if ($v !== null) return decode_escape(hex2bin($v));
                }

                if ($fname === 'pack' && count($args) === 2) {
                    $mode = $this->resolve($args[0]->value);
                    $data = $this->resolve($args[1]->value);
                    if ($mode === 'H*' && $data !== null) return decode_escape(pack('H*', $data));
                }

                if ($fname === 'urldecode' && isset($args[0])) {
                    $v = $this->resolve($args[0]->value);
                    if ($v !== null) return decode_escape(urldecode($v));
                }
            }

            return null;
        }

        public function enterNode(Node $node) {
            $val = $this->resolve($node);
            if ($val !== null) {
                return new Node\Scalar\String_(ansi_whitelist($val));
            }
            return null;
        }
    });

    $stmts = $traverser->traverse($stmts);
    $pretty = new PrettyPrinter\Standard;
    echo $pretty->prettyPrintFile($stmts);
}
/*
function _rename($file) {
    $code = file_get_contents($file);

    // === Rename label & goto (regex) ===
    preg_match_all('/goto\s+([^\s;]+);/u', $code, $gotos);
    preg_match_all('/^([^\s:]+):/mu', $code, $labels);
    $labelMap = [];
    $labelCount = 1;
    $allLabels = array_unique(array_merge($gotos[1], $labels[1]));
    foreach ($allLabels as $l) {
        $labelMap[$l] = 'label' . $labelCount++;
    }
    foreach ($labelMap as $old => $new) {
        $code = preg_replace('/\b' . preg_quote($old, '/') . '\b/u', $new, $code);
    }

    // === AST Rename ===
    $parser = (new PhpParser\ParserFactory)->create(PhpParser\ParserFactory::PREFER_PHP7);
    $prettyPrinter = new PhpParser\PrettyPrinter\Standard;
    $traverser = new PhpParser\NodeTraverser;

    $renamer = new class extends PhpParser\NodeVisitorAbstract {
        public $varMap = [];
        public $funcMap = [];
        public $methodMap = [];
        public $classMap = [];
        public $propertyMap = [];
        private $varCount = 1;
        private $funcCount = 1;
        private $methodCount = 1;
        private $classCount = 1;
        private $propertyCount = 1;

        private $builtinFunctions = [
            'system', 'shell_exec', 'exec', 'passthru', 'exit',
            'print', 'echo', 'var_dump', 'print_r',
            'file_get_contents', 'file_put_contents', 'clearstatcache',
            'is_dir', 'file_exists',
            'json_encode', 'json_decode',
            'date', 'time', 'microtime', 'sleep', 'date_default_timezone_set',
            'error_reporting', 'ini_set', 'getenv', 'putenv',
            'curl_init', 'curl_setopt', 'curl_exec', 'curl_close',
            'str_replace', 'explode', 'implode', 'substr', 'strlen',
            'trim', 'ltrim', 'rtrim', 'strtolower', 'strtoupper', 'ucfirst', 'lcfirst',
            'preg_match', 'preg_replace', 'preg_split', 'preg_quote',
            'intval', 'floatval', 'boolval', 'is_array', 'is_string', 'is_numeric',
            'isset', 'unset',
            'array_merge', 'array_merge_recursive', 'array_map', 'array_filter',
            'array_reduce', 'array_slice', 'array_keys', 'array_values', 'count', 'ksort',
            'md5', 'sha1', 'hash', 'base64_encode', 'base64_decode',
            'serialize', 'unserialize',
            'header', 'http_response_code',
            'readline'
        ];

        public function enterNode(PhpParser\Node $node) {
            // Rename variables
            if ($node instanceof PhpParser\Node\Expr\Variable && is_string($node->name)) {
                $name = $node->name;
                if (!isset($this->varMap[$name])) {
                    $this->varMap[$name] = 'var' . $this->varCount++;
                }
                $node->name = $this->varMap[$name];
            }

            // Rename parameters
            if ($node instanceof PhpParser\Node\Param && $node->var instanceof PhpParser\Node\Expr\Variable) {
                $name = $node->var->name;
                if (is_string($name) && !isset($this->varMap[$name])) {
                    $this->varMap[$name] = 'var' . $this->varCount++;
                }
                if (is_string($name)) {
                    $node->var->name = $this->varMap[$name];
                }
            }

            // Rename function definitions
            if ($node instanceof PhpParser\Node\Stmt\Function_) {
                $name = $node->name->name;
                if (!isset($this->funcMap[$name])) {
                    $this->funcMap[$name] = 'func' . $this->funcCount++;
                }
                $node->name->name = $this->funcMap[$name];
            }

            // Rename class methods
            if ($node instanceof PhpParser\Node\Stmt\ClassMethod) {
                $name = $node->name->name;
                if (!isset($this->methodMap[$name])) {
                    $this->methodMap[$name] = 'method' . $this->methodCount++;
                }
                $node->name->name = $this->methodMap[$name];
            }

            // Rename function calls (skip built-in)
            if ($node instanceof PhpParser\Node\Expr\FuncCall && $node->name instanceof PhpParser\Node\Name) {
                $name = strtolower($node->name->toString());
                if (in_array($name, $this->builtinFunctions)) {
                    return $node;
                }
                if (!isset($this->funcMap[$name])) {
                    $this->funcMap[$name] = 'func' . $this->funcCount++;
                }
                $node->name = new PhpParser\Node\Name($this->funcMap[$name]);
            }

            // Rename method calls
            if ($node instanceof PhpParser\Node\Expr\MethodCall && $node->name instanceof PhpParser\Node\Identifier) {
                $name = $node->name->name;
                if (!isset($this->methodMap[$name])) {
                    $this->methodMap[$name] = 'method' . $this->methodCount++;
                }
                $node->name->name = $this->methodMap[$name];
            }
            
            // Rename instantiation: new ClassName()
            if ($node instanceof PhpParser\Node\Expr\New_ &&
            $node->class instanceof PhpParser\Node\Name) {
                
                $name = $node->class->toString();
                if (isset($this->classMap[$name])) {
                    $node->class = new PhpParser\Node\Name($this->classMap[$name]);
                }
            }


            // Rename class properties ($this->xxx or $obj->xxx)
            if ($node instanceof PhpParser\Node\Expr\PropertyFetch &&
                $node->name instanceof PhpParser\Node\Identifier) {

                $name = $node->name->name;
                if (!isset($this->propertyMap[$name])) {
                    $this->propertyMap[$name] = 'prop' . $this->propertyCount++;
                }
                $node->name->name = $this->propertyMap[$name];
            }

            // Rename class definitions
            if ($node instanceof PhpParser\Node\Stmt\Class_ && $node->name !== null) {
                $name = $node->name->name;
                if (!isset($this->classMap[$name])) {
                    $this->classMap[$name] = 'Class' . $this->classCount++;
                }
                $node->name->name = $this->classMap[$name];
            }

            return $node;
        }
    };

    $traverser->addVisitor(new PhpParser\NodeVisitor\ParentConnectingVisitor);
    $traverser->addVisitor($renamer);

    try {
        $ast = $parser->parse($code);
        $ast = $traverser->traverse($ast);
        $output = $prettyPrinter->prettyPrintFile($ast);
        echo $output;
    } catch (PhpParser\Error $e) {
        echo "[ERROR] Parse error: " . $e->getMessage() . "\n";
    }
}
*/

function _rename($file) {
    $code = file_get_contents($file);

    // === Rename label & goto (regex) ===
    preg_match_all('/goto\s+([^\s;]+);/u', $code, $gotos);
    preg_match_all('/^([^\s:]+):/mu', $code, $labels);
    $labelMap = [];
    $labelCount = 1;
    $allLabels = array_unique(array_merge($gotos[1], $labels[1]));
    foreach ($allLabels as $l) {
        $labelMap[$l] = 'label' . $labelCount++;
    }
    foreach ($labelMap as $old => $new) {
        $code = preg_replace('/\b' . preg_quote($old, '/') . '\b/u', $new, $code);
    }

    // === AST Rename ===
    $parser = (new PhpParser\ParserFactory)->create(PhpParser\ParserFactory::PREFER_PHP7);
    $prettyPrinter = new PhpParser\PrettyPrinter\Standard;
    $traverser = new PhpParser\NodeTraverser;

    $renamer = new class extends PhpParser\NodeVisitorAbstract {
        public $varMap = [];
        public $funcMap = [];
        public $methodMap = [];
        public $classMap = [];
        public $propertyMap = [];
        private $varCount = 1;
        private $funcCount = 1;
        private $methodCount = 1;
        private $classCount = 1;
        private $propertyCount = 1;

        private $builtinFunctions = [
            'system', 'shell_exec', 'exec', 'passthru', 'exit',
            'print', 'echo', 'var_dump', 'print_r',
            'file_get_contents', 'file_put_contents', 'clearstatcache',
            'is_dir', 'file_exists',
            'json_encode', 'json_decode',
            'date', 'time', 'microtime', 'sleep', 'date_default_timezone_set',
            'error_reporting', 'ini_set', 'getenv', 'putenv',
            'curl_init', 'curl_setopt', 'curl_exec', 'curl_close',
            'str_replace', 'explode', 'implode', 'substr', 'strlen',
            'trim', 'ltrim', 'rtrim', 'strtolower', 'strtoupper', 'ucfirst', 'lcfirst',
            'preg_match', 'preg_replace', 'preg_split', 'preg_quote',
            'intval', 'floatval', 'boolval', 'is_array', 'is_string', 'is_numeric',
            'isset', 'unset',
            'array_merge', 'array_merge_recursive', 'array_map', 'array_filter',
            'array_reduce', 'array_slice', 'array_keys', 'array_values', 'count', 'ksort',
            'md5', 'sha1', 'hash', 'base64_encode', 'base64_decode',
            'serialize', 'unserialize',
            'header', 'http_response_code',
            'readline'
        ];

        public function enterNode(PhpParser\Node $node) {
            // Rename variables
            if ($node instanceof PhpParser\Node\Expr\Variable && is_string($node->name)) {
                $name = $node->name;
                if (!isset($this->varMap[$name])) {
                    $this->varMap[$name] = 'var' . $this->varCount++;
                }
                $node->name = $this->varMap[$name];
            }

            // Rename parameters
            if ($node instanceof PhpParser\Node\Param && $node->var instanceof PhpParser\Node\Expr\Variable) {
                $name = $node->var->name;
                if (is_string($name) && !isset($this->varMap[$name])) {
                    $this->varMap[$name] = 'var' . $this->varCount++;
                }
                if (is_string($name)) {
                    $node->var->name = $this->varMap[$name];
                }
            }

            // Rename function definitions
            if ($node instanceof PhpParser\Node\Stmt\Function_) {
                $name = $node->name->toString();
                if (!isset($this->funcMap[$name])) {
                    $this->funcMap[$name] = 'func' . $this->funcCount++;
                }
                $node->name->name = $this->funcMap[$name];
            }

            // Rename class methods
            if ($node instanceof PhpParser\Node\Stmt\ClassMethod) {
                $name = $node->name->toString();
                if (!isset($this->methodMap[$name])) {
                    $this->methodMap[$name] = 'method' . $this->methodCount++;
                }
                $node->name->name = $this->methodMap[$name];
            }

            // Rename function calls (skip built-in)
            if ($node instanceof PhpParser\Node\Expr\FuncCall && $node->name instanceof PhpParser\Node\Name) {
                $name = strtolower($node->name->toString());
                if (in_array($name, $this->builtinFunctions)) {
                    return $node;
                }
                if (!isset($this->funcMap[$name])) {
                    $this->funcMap[$name] = 'func' . $this->funcCount++;
                }
                $node->name = new PhpParser\Node\Name($this->funcMap[$name]);
            }

            // Rename method calls
            if ($node instanceof PhpParser\Node\Expr\MethodCall && $node->name instanceof PhpParser\Node\Identifier) {
                $name = $node->name->toString();
                if (!isset($this->methodMap[$name])) {
                    $this->methodMap[$name] = 'method' . $this->methodCount++;
                }
                $node->name->name = $this->methodMap[$name];
            }

            // Rename instantiation: new ClassName()
            if ($node instanceof PhpParser\Node\Expr\New_ && $node->class instanceof PhpParser\Node\Name) {
                $name = $node->class->toString();
                if (isset($this->classMap[$name])) {
                    $node->class = new PhpParser\Node\Name($this->classMap[$name]);
                }
            }

            // Rename class properties ($this->xxx or $obj->xxx)
            if ($node instanceof PhpParser\Node\Expr\PropertyFetch &&
                $node->name instanceof PhpParser\Node\Identifier) {

                $name = $node->name->toString();
                if (!isset($this->propertyMap[$name])) {
                    $this->propertyMap[$name] = 'prop' . $this->propertyCount++;
                }
                $node->name->name = $this->propertyMap[$name];
            }

            // Rename class definitions
            if ($node instanceof PhpParser\Node\Stmt\Class_ && $node->name !== null) {
                $name = $node->name->toString();
                if (!isset($this->classMap[$name])) {
                    $this->classMap[$name] = 'Class' . $this->classCount++;
                }
                $node->name->name = $this->classMap[$name];
            }

            return $node;
        }
    };

    $traverser->addVisitor(new PhpParser\NodeVisitor\ParentConnectingVisitor);
    $traverser->addVisitor($renamer);

    try {
        $ast = $parser->parse($code);
        $ast = $traverser->traverse($ast);
        $output = $prettyPrinter->prettyPrintFile($ast);
        echo $output;
    } catch (PhpParser\Error $e) {
        echo "[ERROR] Parse error: " . $e->getMessage() . "\n";
    }
}

class get_replaced extends NodeVisitorAbstract{
    public $result;
    public $internal;
    
    public function __construct()
    {
        $this->result = [];
        $this->internal = get_defined_functions()['internal'];
    }
    public function enterNode(Node $node)
    {
        if ($node instanceof PhpParser\Node\Expr\FuncCall || 
            $node instanceof PhpParser\Node\Expr\MethodCall ||
            $node instanceof PhpParser\Node\Stmt\Function_ ||
            $node instanceof PhpParser\Node\Stmt\Class_ ||
            $node instanceof PhpParser\Node\Stmt\ClassMethod) {
            $funcname = $node->name;
            if (property_exists($funcname, 'toString')) {
                $funcname = $funcname->toString();
            }
            if (!in_array($funcname, $this->internal)) {
                $this->result[] = $funcname;
            }
        }
        if ($node instanceof PhpParser\Node\Expr\Variable) {
            $varnames = $node->name;
            if (property_exists($varnames, 'name')) {
                $varnames = $varnames->name;
            }
            $this->result[] = $varnames;
        }
    }
}

/*
 * HELPER PARSER FUNCTION
 */

function isAnsiSequenceAt(string $s, int $i, ?int &$lenOut = null): bool {
    $lenOut = 0;
    $bytes = $s;
    $n = strlen($bytes);
    if ($i >= $n) return false;
    if ($bytes[$i] !== "\x1b") return false;
    if ($i+1 >= $n) return false;
    if ($bytes[$i+1] !== '[') return false;
    $j = $i + 2;
    while ($j < $n) {
        $c = $bytes[$j];
        if (preg_match('/[A-Za-z]/', $c)) {
            $lenOut = $j - $i + 1;
            return true;
        }
        if (!preg_match('/[0-9;?=<>]/', $c)) return false;
        $j++;
    }
    return false;
}

function ansi_whitelist(string $s): string {
    $out = '';
    $i = 0;
    $n = strlen($s);
    while ($i < $n) {
        $ch = $s[$i];
        $ord = ord($ch);
        if ($ch === "\x1b") {
            $len = 0;
            if (isAnsiSequenceAt($s, $i, $len)) {
                $out .= '\\x1b';
                $out .= substr($s, $i+1, $len-1);
                $i += $len;
                continue;
            } else {
                $out .= sprintf('\\x%02x', $ord);
                $i++;
                continue;
            }
        }
        if ($ord >= 0x20 && $ord <= 0x7E) {
            $out .= $ch;
            $i++;
            continue;
        }
        if ($ord >= 0x80) {
            $out .= $ch;
            $i++;
            continue;
        }
        $out .= sprintf('\\x%02x', $ord);
        $i++;
    }
    return $out;
}
 
function decode_escape(string $s): string {
    $s = str_replace(
        ["\\n","\\r","\\t","\\v","\\e","\\f","\\\\","\\\""],
        ["\n","\r","\t","\x0B","\x1B","\x0C","\\","\""],
        $s
    );
    $s = preg_replace_callback('/\\\\x([0-9A-Fa-f]{2})/', fn($m) => chr(hexdec($m[1])), $s);
    $s = preg_replace_callback('/\\\\([0-7]{1,3})/', fn($m) => chr(intval($m[1], 8)), $s);
    $s = preg_replace_callback('/\\\\u\{([0-9A-Fa-f]{1,6})\}/', function($m){
        $cp = hexdec($m[1]);
        return function_exists('mb_chr') ? mb_chr($cp, 'UTF-8') : html_entity_decode("&#$cp;", ENT_NOQUOTES, 'UTF-8');
    }, $s);
    return $s;
}

function requote(string $s): string {
    return '"' . addcslashes($s, "\\\"") . '"';
}


if (php_sapi_name() === 'cli') {
    // Jalankan web server jika flag 'web-mode' diberikan
    if (isset($argv[1]) && $argv[1] === 'web-mode') {
        echo "Starting web server at http://localhost:4262.\n";
        $cmd = "php -S localhost:4262 > /dev/null 2>&1 &";
        shell_exec($cmd);
        echo "open http://localhost:4262/parser.php\n";
        exit;
    }

    /** CLI MODE
     * usage: php parser.php <mode> <file>
     */
    if ($argc < 3) {
        die("Usage: php parser.php <mode> <file>\n");
    }

    $mode = $argv[1];
    $file = $argv[2];
    switch ($mode) {
        case 'hex': _hex($file); break;
        case 'eval': _eval($file); break;
        case 'simply': _simply($file); break;
        case 'literal': _literal($file); break;
        case 'globals': _globals($file); break;
        case 'goto': _goto($file); break;
        case 'pars': _pars($file); break;
        case 'rename': _rename($file); break;
        default: echo "Unknown mode: $mode\n"; break;
    }
} elseif (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    /** WEB MODE
     * http://localhost:4262/parser.php
     */
    $mode = $_POST['mode'] ?? '';
    $code = '';
    if (!empty($_FILES['phpfile']['tmp_name'])) {
        $code = file_get_contents($_FILES['phpfile']['tmp_name']);
    }
    if (!empty($_POST['phptext'])) {
        $code = $_POST['phptext'];
    }
    if ($code === '') {
        echo "<h3 style='color:red;'>No input provided.</h3>";
        exit;
    }
    $tempFile = __DIR__ . '/temp_input.php';
    file_put_contents($tempFile, $code);
    ob_start();
    switch ($mode) {
        case 'hex': _hex($tempFile); break;
        case 'eval': _eval($tempFile); break;
        case 'simply': _simply($tempFile); break;
        case 'literal': _literal($tempFile); break;
        case 'globals': _globals($tempFile); break;
        case 'goto': _goto($tempFile); break;
        case 'pars': _pars($tempFile); break;
        case 'rename': _rename($tempFile); break;
        default: echo "Unknown mode: $mode"; break;
    }
    $output = ob_get_clean();
    echo <<<HTML
    <h3>Parsed Output:</h3>
    <pre>{$output}</pre>
HTML;
    exit;
}
if (php_sapi_name() !== 'cli' && isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'GET') {
?>
<html>
<head>
  <title>WEB MODE</title>
  <style>
    body {
      font-family: sans-serif;
      padding: 20px;
      background-color: #f9f9f9;
    }
    form {
      background: #fff;
      padding: 20px;
      border: 1px solid #ccc;
      margin-bottom: 30px;
    }
    textarea {
      font-family: monospace;
      width: 100%;
      box-sizing: border-box;
    }
    pre {
      background: #272822;
      color: #f8f8f2;
      padding: 15px;
      border-radius: 5px;
      overflow-x: auto;
      font-family: monospace;
      font-size: 14px;
    }
    label {
      font-weight: bold;
    }
    select, input[type="file"], input[type="submit"] {
      margin-top: 5px;
      margin-bottom: 15px;
    }
  </style>
</head>
<body>
  <h2>PHP Parser</h2>
  <form method="POST" enctype="multipart/form-data">
    <label>Choose Mode:</label><br>
    <select name="mode">
      <option value="hex">_hex</option>
      <option value="eval">_eval</option>
      <option value="simply">_simply</option>
      <option value="literal">_literal</option>
      <option value="globals">_globals</option>
      <option value="goto">_goto</option>
      <option value="pars">_pars</option>
      <option value="rename">_rename</option>
    </select>
    <br><br>
    <label>Upload PHP File:</label><br>
    <input type="file" name="phpfile">
    <br><br>
    <label>Or Paste PHP Code:</label><br>
    <textarea name="phptext" rows="20" cols="100"></textarea>
    <br><br>
    <input type="submit" value="Run Parser">
  </form>
</body>
</html>
<?php
}