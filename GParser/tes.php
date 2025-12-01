<?php

/* ADVANCED PHP PARSER DEOBFUSCATOR. 
/***    PHP PARSER powered by nikic\PhpParser
        using parser v4^
        usage <file> <mode>
        see result demo
 */

include "vendor/autoload.php";
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
    //$known = _simply($file, false);
    $code = file_get_contents($file);
    if ($code === false) {
        echo "Cannot read file: $file";
        return;
    }
    $parser = (new ParserFactory)->create(ParserFactory::PREFER_PHP7);
    try {
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
        }
        private function resolveNodeToString(Node $node) {
            // direct string
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
        }
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
        }
        private function tryParseInnerReturn($codeString) {
            $trial = $codeString;
            try {
                $stmts = $this->parser->parse('<?php ' . $trial);
            } catch (Error $e) {
                $trial2 = stripcslashes($trial);
                try {
                    $stmts = $this->parser->parse('<?php ' . $trial2);
                    $trial = $trial2;
                } catch (Error $e2) {
                    return null;
                }
            }
            if (!is_array($stmts) || count($stmts) === 0) return null;
            foreach ($stmts as $st) {
                if ($st instanceof Node\Stmt\Return_ && $st->expr instanceof Node\Expr) {
                    $val = $this->evalExprToString($st->expr);
                    if ($val !== null) return $val;
                }
            }
            return null;
        }
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
    });
    $stmts = $traverser->traverse($stmts);
    $pretty = new PrettyPrinter\Standard;
    echo $pretty->prettyPrintFile($stmts);
}

function _simply($file, $print = true) {
    $code = file_get_contents($file);
    $parser = (new ParserFactory)->create(ParserFactory::PREFER_PHP7);
    $pretty = new PrettyPrinter\Standard;
    try {
        $stmts = $parser->parse($code);
    } catch (Throwable $e) {
        echo "Parse error: " . $e->getMessage() . PHP_EOL;
        return;
    }
    $known = [];
    $collector = new class($known) extends NodeVisitorAbstract {
        private $known;
        public function __construct(&$knownRef) { $this->known =& $knownRef; }
        private function resolveNode(Node $node) {
            // string literal
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
        }
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
    };
    $tr2 = new NodeTraverser();
    $tr2->addVisitor(new ParentConnectingVisitor());
    $tr2->addVisitor($replacer);
    $stmts = $tr2->traverse($stmts);
    if ($print) echo $pretty->prettyPrintFile($stmts);
    return $known;
}

function _literal($file) {
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
    try {
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
    };
    $trB = new NodeTraverser();
    $trB->addVisitor($replacer);
    $stmts = $trB->traverse($stmts);
    $decl = "";
    foreach ($known as $orig => $info) {
        $decl .= "\$" . $info['safe'] . " = " . var_export($info['value'], true) . ";\n";
    }
    echo $decl . "\n" . $pretty->prettyPrintFile($stmts);
}

function _globals(string $file) {
    $code = file_get_contents($file);
    if ($code === false) { echo "Cannot read file: $file\n"; return; }
    $parser = (new ParserFactory)->create(ParserFactory::PREFER_PHP7);
    $pretty = new PrettyPrinter\Standard;
    try {
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
    $tr1->traverse($stmts);
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
        }

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
            }
            if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name) {
                if (strtolower($node->name->toString()) === 'define' && isset($node->args[0]) && isset($node->args[1])) {
                    $nameNode = $node->args[0]->value;
                    $valNode = $node->args[1]->value;
                    $tryResolve = function($n) {
                        if ($n instanceof Node\Scalar\String_) return $n->value;
                        if ($n instanceof Node\Scalar\Encapsed) {
                            $out = '';
                            foreach ($n->parts as $p) {
                                if ($p instanceof Node\Scalar\EncapsedStringPart) { $out .= $p->value; continue; }
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
    $decl = "";
    foreach ($knownGlobals as $k => $v) {
        $safe = $sanitizeKey($k);
        $v_safe = ansi_whitelist($v);
        $decl .= '$' . $safe . ' = ' . var_export($v_safe, true) . ";\n";
    }
    echo $decl . "\n" . $pretty->prettyPrintFile($stmts2);
}

function _goto($file) {
    $code = @file_get_contents($file);
    if ($code === false) { echo "Cannot read file: $file\n"; return; }
    // normalize endings
    $code = str_replace(["\r\n", "\r"], "\n", $code);

    // --- Step 1: tokenize and mask strings/comments/heredoc ---
    $tokens = token_get_all($code);
    $out = '';
    $placeholders = []; // placeholder => original text
    $strCounter = 0;

    foreach ($tokens as $t) {
        if (is_array($t)) {
            $id = $t[0];
            $text = $t[1];

            // mask string-like tokens and comments, leave others
            if ($id === T_CONSTANT_ENCAPSED_STRING || $id === T_ENCAPSED_AND_WHITESPACE ||
                $id === T_START_HEREDOC || $id === T_END_HEREDOC ||
                $id === T_DOC_COMMENT || $id === T_COMMENT) {

                $ph = "__PH_STR_" . $strCounter . "__";
                $placeholders[$ph] = $text;
                $out .= $ph;
                $strCounter++;
                continue;
            }
            // everything else: append raw text
            $out .= $text;
        } else {
            // single-char token like ; { } etc
            $out .= $t;
        }
    }

    // Now $out is the masked code (strings/comments replaced by placeholders).
    // --- Step 2: perform goto/label pass on masked code ---

    $lines = explode("\n", $out);
    $labels = [];
    foreach ($lines as $i => $line) {
        if (preg_match('/^\s*(\w+)\s*:/', $line, $m)) {
            $labels[$m[1]] = $i;
        }
    }

    $result = [];
    $i = 0;
    $visited = [];
    $n = count($lines);
    while ($i < $n) {
        if (isset($visited[$i])) break; // avoid infinite loop
        $visited[$i] = true;
        $line = $lines[$i];

        // if a goto, jump to label if known
        if (preg_match('/^\s*goto\s+(\w+)\s*;/', $line, $m)) {
            if (isset($labels[$m[1]])) {
                $i = $labels[$m[1]];
                continue;
            }
            // unknown label: treat as normal line (or optionally keep)
        }

        // skip label definitions
        if (preg_match('/^\s*(\w+)\s*:/', $line)) {
            $i++;
            continue;
        }

        $result[] = $line;
        $i++;
    }

    $maskedResult = implode("\n", $result);

    // --- Step 3: restore placeholders back to original text ---
    if (!empty($placeholders)) {
        // restore longest placeholders first (avoid partial collisions)
        uksort($placeholders, function($a,$b){ return strlen($b) - strlen($a); });
        foreach ($placeholders as $ph => $orig) {
            $maskedResult = str_replace($ph, $orig, $maskedResult);
        }
    }

    // print final result
    echo $maskedResult;
}

function _pars($file) {
    $code = file_get_contents($file);
    $prettyPrinter = new PrettyPrinter\Standard;
    $parser = (new ParserFactory)->create(ParserFactory::PREFER_PHP7);
    $p = $parser->parse($code);
    echo str_replace("    ","\t",$prettyPrinter->prettyPrint($p));
}

function _hex($file) {
    $code = file_get_contents($file);
    $tokens = token_get_all($code);
    $out = '';

    foreach ($tokens as $tok) {
        if (is_array($tok)) {
            $id = $tok[0];
            $text = $tok[1];
            if ($id === T_CONSTANT_ENCAPSED_STRING) {
                $firstChar = $text[0];
                $prefix = '';
                if (!in_array($firstChar, ['"', "'"])) {
                    $prefix = $firstChar;
                    $firstChar = $text[1] ?? '';
                }
                if ($firstChar === '"' || $firstChar === "'") {
                    $start = ($prefix === '') ? 1 : 2;
                    $quote = $firstChar;
                    $body = substr($text, $start, -1);
                    if ($quote === '"') {
                        $decoded = doublequote($body);
                        $decoded = ansi_whitelist($decoded);
                        $out .= $prefix . requote($decoded);
                    } else {
                        $decoded = singlequote($body);
                        $decoded = ansi_whitelist($decoded);
                        $escaped = str_replace(["\\","'"], ["\\\\","\\'"], $decoded);
                        $out .= $prefix . "'" . $escaped . "'";
                    }
                    continue;
                }
                $out .= $text;
                continue;
            }
            if ($id === T_ENCAPSED_AND_WHITESPACE) {
                $decoded = doublequote($text);
                $decoded = ansi_whitelist($decoded);
                $out .= $decoded;
                continue;
            }
            if ($id === T_START_HEREDOC || $id === T_END_HEREDOC) {
                $out .= $text;
                continue;
            }
            $out .= $text;
        } else {
            $out .= $tok;
        }
    }
    echo $out;
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
 
function doublequote(string $s): string {
    $s = str_replace(
        ["\\n","\\r","\\t","\\v","\\e","\\f","\\\\" ,"\\\""],
        ["\n", "\r", "\t", "\x0B", "\x1B", "\x0C", "\\", "\""],
        $s
    );
    $s = preg_replace_callback('/\\\\x([0-9A-Fa-f]{2})/', function($m){
        return chr(hexdec($m[1]));
    }, $s);
    $s = preg_replace_callback('/\\\\([0-7]{1,3})/', function($m){
        $val = intval($m[1], 8);
        if ($val >= 32 || in_array($val, [9,10,13])) return chr($val);
        return $m[0];
    }, $s);
    $s = preg_replace_callback('/\\\\u\{([0-9A-Fa-f]{1,6})\}/', function($m){
        $cp = hexdec($m[1]);
        if (function_exists('mb_chr')) {
            return mb_chr($cp, 'UTF-8');
        }
        return html_entity_decode('&#'.intval($cp).';', ENT_NOQUOTES, 'UTF-8');
    }, $s);

    return $s;
}

function singlequote(string $s): string {
    $s = str_replace(["\\\\","\\'"], ["\\","'"], $s);
    return $s;
}

function requote(string $decoded): string {
    $escaped = addcslashes($decoded, "\\\"");
    return '"' . $escaped . '"';
}

if (count($argv) < 3) {
    die("usage: <mode> <file>\n");
} else {
    $mode = $argv[1];
    $file = $argv[2];
    switch ($mode) {
        case 'simply':
            _simply($file);
            break;
        case 'goto':
            _goto($file);
            break;
        case 'literal':
            _literal($file);
            break;
        case 'pars':
            _pars($file);
            break;
        case 'hex':
            _hex($file);
            break;
        case 'eval':
            _eval($file);
            break;
        case 'global':
            _globals($file);
            break;
    }
}