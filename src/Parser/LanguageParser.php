<?php

declare(strict_types=1);

namespace Bugo\Antlers\Parser;

use Bugo\Antlers\Exceptions\AntlersSyntaxException;
use Bugo\Antlers\Nodes\AbstractNode;
use Bugo\Antlers\Nodes\AntlersNode;
use Bugo\Antlers\Nodes\ArrayNode;
use Bugo\Antlers\Nodes\AssignmentNode;
use Bugo\Antlers\Nodes\BinaryOpNode;
use Bugo\Antlers\Nodes\BooleanNode;
use Bugo\Antlers\Nodes\CollectionGroupArgument;
use Bugo\Antlers\Nodes\CollectionOperationNode;
use Bugo\Antlers\Nodes\CollectionOperatorNode;
use Bugo\Antlers\Nodes\CollectionSortArgument;
use Bugo\Antlers\Nodes\ConditionBranch;
use Bugo\Antlers\Nodes\ConditionNode;
use Bugo\Antlers\Nodes\GatekeeperNode;
use Bugo\Antlers\Nodes\LoopNode;
use Bugo\Antlers\Nodes\ModifierChainNode;
use Bugo\Antlers\Nodes\ModifierNode;
use Bugo\Antlers\Nodes\NullCoalesceNode;
use Bugo\Antlers\Nodes\NullNode;
use Bugo\Antlers\Nodes\NumberNode;
use Bugo\Antlers\Nodes\SequenceNode;
use Bugo\Antlers\Nodes\SetNode;
use Bugo\Antlers\Nodes\StringValueNode;
use Bugo\Antlers\Nodes\TagNode;
use Bugo\Antlers\Nodes\TernaryNode;
use Bugo\Antlers\Nodes\TruthyCoalesceNode;
use Bugo\Antlers\Nodes\UnaryOpNode;
use Bugo\Antlers\Nodes\VariableNode;
use Bugo\Antlers\Nodes\VoidNode;
use Bugo\Antlers\Tags\NameResolver;
use Bugo\Antlers\Tags\TagRegistry;

/**
 * Stage 3: Parses AntlersNode raw content + children into typed AST nodes.
 *
 * Uses a Pratt (top-down operator precedence) parser for expressions.
 */
final class LanguageParser
{
    private readonly Lexer $lexer;

    private readonly NameResolver $names;

    private TokenStream $stream;

    /** Template line the fragment currently being parsed starts on. */
    private int $baseLine = 1;

    public function __construct(?NameResolver $names = null)
    {
        $this->lexer  = new Lexer();
        $this->names  = $names ?? new NameResolver(new TagRegistry());
        $this->stream = new TokenStream([]);
    }

    /**
     * Parse a single AntlersNode into a typed AST node.
     * Children (for block tags) are processed recursively.
     */
    public function parseNode(AntlersNode $node): AbstractNode
    {
        $parsed = $this->parseNodeContent($node);

        // Everything parsed out of this {{ }} belongs to its line, so runtime
        // failures can be attributed to it without threading a position through
        // evaluation.
        if ($parsed->line === 0) {
            $parsed->line = $node->line;
        }

        return $parsed;
    }

    private function parseNodeContent(AntlersNode $node): AbstractNode
    {
        $raw = $node->rawContent;

        $this->baseLine = $node->line;

        // Closing tags don't need parsing
        if ($node->isClosingTag) {
            return $node;
        }

        // {{ noparse }} — wrap children as-is
        if ($node->name === 'noparse') {
            return $node; // NodeProcessor handles this
        }

        // {{ if ... }} / {{ unless ... }}
        if ($node->name === 'if' || $node->name === 'unless') {
            return $this->parseConditionNode($node);
        }

        // {{ foreach items as item }} / {{ foreach items as key => value }}
        // {{ foreach:items }} / {{ foreach array="items" }}
        if ($node->name === 'foreach') {
            $foreachNode = $this->parseForeachNode($node);
            if ($foreachNode instanceof LoopNode) {
                return $foreachNode;
            }

            return $this->parseTagNode($node);
        }

        if (str_starts_with($node->name, 'foreach:')) {
            return $this->parseTagNode($node);
        }

        // {{ for 1 to 10 }}
        if ($node->name === 'for') {
            return $this->parseForNode($node);
        }

        // {{ set variable = value }}
        if ($node->name === 'set') {
            return $this->parseSetNode($raw);
        }

        if (str_starts_with($raw, '%')) {
            return $this->parseTagNode($node);
        }

        if ($node->children !== [] && str_contains($node->name, ':')) {
            return $this->parseTagNode($node);
        }

        if ($this->names->isTag($node->name)) {
            return $this->parseTagNode($node);
        }

        // {{ tag:method param="value" }} or {{ tag param="value" }}
        if ($this->looksLikeTag($raw)) {
            return $this->parseTagNode($node);
        }

        // Default: parse as expression (variable, arithmetic, modifier chain, assignment, etc.)
        $parsed = $this->parseExpression($raw);

        if ($parsed instanceof AssignmentNode && $node->children !== []) {
            $parsed->children = $this->processChildren($node->children);
        }

        return $parsed;
    }

    private function parseConditionNode(AntlersNode $blockNode): ConditionNode
    {
        $condition       = new ConditionNode();
        $condition->line = $blockNode->line;

        // First branch is the `if` / `unless`
        $firstType      = $blockNode->name; // 'if' | 'unless'
        $conditionExpr  = substr($blockNode->rawContent, strlen($firstType));
        $branch         = new ConditionBranch($firstType, $this->parseExpression(trim($conditionExpr)));

        // Walk children looking for elseif/else separators
        $current        = [];
        $pendingBranch  = $branch;

        foreach ($blockNode->children as $child) {
            if ($child instanceof AntlersNode && ! $child->isClosingTag) {
                $childName = $child->name;
                if ($childName === 'elseif') {
                    $pendingBranch->children = $current;
                    $condition->branches[]   = $pendingBranch;

                    $current       = [];
                    $elseExpr      = substr($child->rawContent, strlen('elseif'));
                    $pendingBranch = new ConditionBranch('elseif', $this->parseExpression(trim($elseExpr)));

                    continue;
                }

                if ($childName === 'else') {
                    $pendingBranch->children = $current;
                    $condition->branches[]   = $pendingBranch;

                    $current       = [];
                    $pendingBranch = new ConditionBranch('else', null);

                    continue;
                }
            }

            $current[] = $this->processChild($child);
        }

        $pendingBranch->children = $current;
        $condition->branches[]   = $pendingBranch;

        return $condition;
    }

    private function parseForeachNode(AntlersNode $blockNode): ?LoopNode
    {
        // foreach items as item
        // foreach items as key => value
        $raw = trim(substr($blockNode->rawContent, strlen('foreach')));
        $parts = preg_split('/\s+as\s+/i', $raw, 2);
        if (! is_array($parts) || count($parts) !== 2) {
            return null;
        }

        $iterableExpr = $this->parseExpression(trim($parts[0]));
        $aliasPart    = trim($parts[1]);

        $keyAlias = null;
        $alias    = $aliasPart;

        // key => value syntax
        if (str_contains($aliasPart, '=>')) {
            $exploded = explode('=>', $aliasPart, 2);
            $keyAlias = trim($exploded[0]);
            $alias    = trim($exploded[1] ?? $aliasPart);
        }

        $loop           = new LoopNode('foreach', $iterableExpr, $alias, $keyAlias);
        $loop->line     = $blockNode->line;
        $loop->children = $this->processChildren($blockNode->children);

        return $loop;
    }

    private function parseForNode(AntlersNode $blockNode): LoopNode
    {
        // for 1 to 10  OR  for start to end
        $raw = trim(substr($blockNode->rawContent, strlen('for')));

        if (! preg_match('/^(.+?)\s+to\s+(.+)$/i', $raw, $m)) {
            throw new AntlersSyntaxException(
                sprintf('Invalid for syntax: {{ %s }}', $blockNode->rawContent),
                $blockNode->line,
            );
        }

        $from = $this->parseExpression(trim($m[1]));
        $to   = $this->parseExpression(trim($m[2]));

        $loop           = new LoopNode('for', null, null, null, $from, $to);
        $loop->line     = $blockNode->line;
        $loop->children = $this->processChildren($blockNode->children);

        return $loop;
    }

    private function parseSetNode(string $raw): SetNode
    {
        // set variable = expression
        $content = trim(substr($raw, strlen('set')));
        $eqPos   = strpos($content, '=');

        if ($eqPos === false) {
            throw new AntlersSyntaxException(
                sprintf('Invalid set syntax: {{ %s }}', $raw),
                $this->baseLine,
            );
        }

        $varName  = trim(substr($content, 0, $eqPos));
        $valExpr  = trim(substr($content, $eqPos + 1));

        return new SetNode($varName, $this->parseExpression($valExpr));
    }

    private function parseTagNode(AntlersNode $blockNode): TagNode
    {
        $raw    = ltrim($blockNode->rawContent, '%');
        $method = 'index';

        // Extract tag name (possibly tag:method)
        preg_match('/^(\w+(?::\w+)?)/', $raw, $m);

        $fullName = $m[1] ?? '';
        $rest     = ltrim(substr($raw, strlen($fullName)));

        $tagName = $fullName;
        if (str_contains($fullName, ':')) {
            $colonParts = explode(':', $fullName, 2);
            $tagName    = $colonParts[0];
            $method     = $colonParts[1] ?? 'index';
        }

        $params   = $this->parseTagParameters($rest);
        $children = $blockNode->children;

        $tag       = new TagNode($tagName, $method, $params, $children, $children !== []);
        $tag->line = $blockNode->line;

        return $tag;
    }

    /**
     * @return array<string, AbstractNode>
     */
    private function parseTagParameters(string $raw): array
    {
        $params = [];
        $raw    = trim($raw);

        if ($raw === '') {
            return $params;
        }

        // Parse: key="value" key='value' key=variable key=true boolean_flag
        $pos    = 0;
        $length = strlen($raw);

        while ($pos < $length) {
            // Skip whitespace
            while ($pos < $length && ctype_space($raw[$pos])) {
                $pos++;
            }

            // Read key
            $keyStart  = $pos;
            $isDynamic = $raw[$pos] === ':';

            if ($isDynamic) {
                $pos++;
            }

            if ($isDynamic && $pos < $length && $raw[$pos] === '$') {
                $pos++;

                $nameStart = $pos;
                while ($pos < $length && (ctype_alnum($raw[$pos]) || $raw[$pos] === '_' || $raw[$pos] === '-')) {
                    $pos++;
                }

                $variable = substr($raw, $nameStart, $pos - $nameStart);
                if ($variable === '') {
                    break;
                }

                $params[$variable] = new VariableNode($variable);

                continue;
            }

            while ($pos < $length && (ctype_alnum($raw[$pos]) || $raw[$pos] === '_' || $raw[$pos] === '-')) {
                $pos++;
            }

            $key = substr($raw, $keyStart, $pos - $keyStart);
            if ($key === '') {
                break;
            }

            if ($isDynamic) {
                $key = ltrim($key, ':');
            }

            // Skip whitespace
            while ($pos < $length && ctype_space($raw[$pos])) {
                $pos++;
            }

            // If next char is = then we have a value
            if ($pos < $length && $raw[$pos] === '=') {
                $pos++; // skip =

                // Skip whitespace
                while ($pos < $length && ctype_space($raw[$pos])) {
                    $pos++;
                }

                // Read value
                if ($pos < $length && ($raw[$pos] === '"' || $raw[$pos] === "'")) {
                    $quote    = $raw[$pos++];
                    $valStart = $pos;

                    while ($pos < $length && $raw[$pos] !== $quote) {
                        if ($raw[$pos] === '\\') {
                            $pos++;
                        }

                        $pos++;
                    }

                    $val = substr($raw, $valStart, $pos - $valStart);

                    $pos++; // skip closing quote

                    $params[$key] = $isDynamic ? $this->parseDynamicParameterValue($val) : $this->makeStringNode($val);
                } else {
                    // Unquoted value — read until whitespace
                    $valStart = $pos;
                    while ($pos < $length && ! ctype_space($raw[$pos])) {
                        $pos++;
                    }

                    $val = substr($raw, $valStart, $pos - $valStart);

                    $params[$key] = $isDynamic ? $this->parseDynamicParameterValue($val) : $this->parseExpression($val);
                }
            } else {
                // Boolean flag (no =)
                $params[$key] = new BooleanNode(true);
            }
        }

        return $params;
    }

    /**
     * @param  AbstractNode[] $children
     * @return AbstractNode[]
     */
    private function processChildren(array $children): array
    {
        return array_map($this->processChild(...), $children);
    }

    private function processChild(AbstractNode $child): AbstractNode
    {
        if ($child instanceof AntlersNode) {
            return $this->parseNode($child);
        }

        return $child;
    }

    public function parseExpression(string $input): AbstractNode
    {
        $input = trim($input);

        return $this->withStream(
            new TokenStream($this->lexer->tokenize($input, $this->baseLine), $input, $this->baseLine),
            $this->parseStatementSequence(...),
        );
    }

    /**
     * The single place the token cursor is switched. Everything that parses a
     * sub-expression goes through here, so an inner parse cannot leave the outer
     * one pointing into a foreign token list.
     *
     * @template T
     * @param  callable(): T $parse
     * @return T
     */
    private function withStream(TokenStream $stream, callable $parse): mixed
    {
        $previous     = $this->stream;
        $this->stream = $stream;

        try {
            return $parse();
        } finally {
            $this->stream = $previous;
        }
    }

    /**
     * A stream over a slice of the current one, keeping its position context.
     *
     * @param list<Token> $tokens
     */
    private function subStream(array $tokens): TokenStream
    {
        return new TokenStream($tokens, $this->stream->source, $this->stream->baseLine);
    }

    private function parseStatementSequence(TokenType $terminator = TokenType::Eof): AbstractNode
    {
        if ($this->peek()->is($terminator, TokenType::Eof)) {
            $this->syntaxError('Expected an expression');
        }

        $statements   = [];
        $statements[] = $this->parseAssignmentExpression();

        while (true) {
            $token = $this->peek();
            if (! $token->is(TokenType::Semicolon)) {
                break;
            }

            $this->advance();

            $token = $this->peek();
            while ($token->is(TokenType::Semicolon)) {
                $this->advance();

                $token = $this->peek();
            }

            if ($token->is($terminator, TokenType::Eof)) {
                break;
            }

            $statements[] = $this->parseAssignmentExpression();
        }

        $token = $this->peek();
        if (! $token->is($terminator, TokenType::Eof)) {

            $this->syntaxError(
                sprintf('Unexpected %s in expression', $this->describeToken($token)),
                $token,
            );
        }

        if (count($statements) === 1) {
            return $statements[0];
        }

        return new SequenceNode($statements);
    }

    private function parseAssignmentExpression(): AbstractNode
    {
        $expr = $this->parseTernaryExpression();

        $operator = $this->peek();
        if (! $operator->is(
            TokenType::Equals,
            TokenType::PlusEquals,
            TokenType::MinusEquals,
            TokenType::StarEquals,
            TokenType::SlashEquals,
            TokenType::PercentEquals,
        )) {
            return $expr;
        }

        if (! $expr instanceof VariableNode) {
            $this->syntaxError('Assignment target must be a variable path');
        }

        $this->advance();

        $value = $this->parseAssignmentExpression();

        if ($operator->is(TokenType::Equals)) {
            return new AssignmentNode($expr->path, $value);
        }

        return new AssignmentNode(
            $expr->path,
            new BinaryOpNode(
                new VariableNode($expr->path),
                $this->compoundAssignmentOperator($operator),
                $value,
            ),
        );
    }

    private function parseTernaryExpression(): AbstractNode
    {
        $expr = $this->parsePipedExpression();

        if (! $this->peek()->is(TokenType::Question)) {
            return $expr;
        }

        $this->consume(TokenType::Question);

        $trueBranch = $this->parseTokenSlice($this->collectTernaryBranchTokens());

        $this->consume(TokenType::Colon);

        $falseBranch = $this->parseTernaryExpression();

        return new TernaryNode($expr, $trueBranch, $falseBranch);
    }

    /**
     * @return list<Token>
     */
    private function collectTernaryBranchTokens(): array
    {
        $start        = $this->stream->position();
        $cursor       = $start;
        $parenDepth   = 0;
        $bracketDepth = 0;
        $ternaryDepth = 0;

        while (true) {
            $token = $this->stream->at($cursor);

            if ($token->is(TokenType::Eof)) {
                $this->syntaxError('Unterminated ternary expression', $token);
            }

            if ($token->is(TokenType::LParen)) {
                $parenDepth++;
                $cursor++;

                continue;
            }

            if ($token->is(TokenType::RParen)) {
                $parenDepth--;
                $cursor++;

                continue;
            }

            if ($token->is(TokenType::LBracket)) {
                $bracketDepth++;
                $cursor++;

                continue;
            }

            if ($token->is(TokenType::RBracket)) {
                $bracketDepth--;
                $cursor++;

                continue;
            }

            if ($parenDepth === 0 && $bracketDepth === 0) {
                if ($token->is(TokenType::Question)) {
                    $ternaryDepth++;
                    $cursor++;

                    continue;
                }

                if ($token->is(TokenType::Colon)) {
                    if ($ternaryDepth === 0) {
                        break;
                    }

                    $ternaryDepth--;
                }
            }

            $cursor++;
        }

        $this->stream->seek($cursor);

        return $this->stream->slice($start, $cursor - $start);
    }

    private function parsePipedExpression(): AbstractNode
    {
        $expr = $this->parseGatekeeperExpression();

        if ($this->peek()->is(TokenType::Pipe) && ! $this->isCollectionOperator($this->peek())) {
            return $this->parseModifierChain($expr);
        }

        return $expr;
    }

    private function parseGatekeeperExpression(): AbstractNode
    {
        $expr = $this->parseCollectionExpression();

        if (! $this->peek()->is(TokenType::QEquals)) {
            return $expr;
        }

        $this->consume(TokenType::QEquals);

        return new GatekeeperNode($expr, $this->parseTernaryExpression());
    }

    private function parseCollectionExpression(): AbstractNode
    {
        $expr       = $this->parseExpr(0);
        $operations = [];

        while ($this->isCollectionOperator($this->peek())) {
            $operator = CollectionOperator::from(strtolower($this->advance()->value));

            $operations[] = match ($operator) {
                CollectionOperator::Take,
                CollectionOperator::Skip,
                CollectionOperator::Pluck   => new CollectionOperatorNode($operator->value, [$this->parseSingleCollectionArgument()]),
                CollectionOperator::Merge   => new CollectionOperatorNode($operator->value, [$this->parseExpr(0)]),
                CollectionOperator::Where   => $this->parseWhereCollectionOperator(),
                CollectionOperator::OrderBy => $this->parseOrderByCollectionOperator(),
                CollectionOperator::GroupBy => $this->parseGroupByCollectionOperator(),
            };
        }

        if ($operations === []) {
            return $expr;
        }

        return new CollectionOperationNode($expr, $operations);
    }

    /**
     * @param list<Token> $tokens
     */
    private function parseTokenSlice(array $tokens): AbstractNode
    {
        return $this->withStream($this->subStream($tokens), $this->parseStatementSequence(...));
    }



    /**
     * Pratt expression parser with operator precedence.
     */
    private function parseExpr(int $minBp): AbstractNode
    {
        $left = $this->parseUnary();

        while (true) {
            $op = $this->peek();
            if ($op->is(TokenType::Eof)) {
                break;
            }

            $bp = $this->infixBp($op);
            if ($bp === null || $bp <= $minBp) {
                break;
            }

            $this->advance();

            // Right-associative operators: ??, ???, **, ^
            if ($op->is(TokenType::QQ, TokenType::QQQ, TokenType::Power, TokenType::Caret)) {
                $right = $this->parseExpr($bp - 1);

                if ($op->is(TokenType::QQ)) {
                    $left = new TruthyCoalesceNode($left, $right);
                } elseif ($op->is(TokenType::QQQ)) {
                    $left = new NullCoalesceNode($left, $right);
                } else {
                    $left = new BinaryOpNode($left, $op->value, $right);
                }

                continue;
            }

            // Left-associative operators
            $right = $this->parseExpr($bp);
            $left  = new BinaryOpNode($left, $op->value, $right);
        }

        return $left;
    }

    private function parseUnary(): AbstractNode
    {
        $token = $this->peek();

        if ($token->is(TokenType::Not)) {
            $this->advance();

            $operand = $this->parseUnary();

            return new UnaryOpNode('!', $operand);
        }

        if ($token->is(TokenType::Minus)) {
            $this->advance();

            $operand = $this->parseUnary();

            return new UnaryOpNode('-', $operand);
        }

        return $this->parsePrimary();
    }

    private function parsePrimary(): AbstractNode
    {
        $token = $this->peek();

        // Grouped expression
        if ($token->is(TokenType::LParen)) {
            $this->advance();

            $expr = $this->parseStatementSequence(TokenType::RParen);

            $this->consume(TokenType::RParen);

            return $expr;
        }

        if ($token->is(TokenType::LBracket)) {
            return $this->parseArrayLiteral();
        }

        // Number literal
        if ($token->is(TokenType::Number)) {
            $this->advance();

            $val = str_contains($token->value, '.') ? (float) $token->value : (int) $token->value;

            return new NumberNode($val);
        }

        // String literal
        if ($token->is(TokenType::String)) {
            $this->advance();

            return $this->makeStringNode($token->value);
        }

        // Boolean literals
        if ($token->is(TokenType::True)) {
            $this->advance();

            return new BooleanNode(true);
        }

        if ($token->is(TokenType::False)) {
            $this->advance();

            return new BooleanNode(false);
        }

        // Null
        if ($token->is(TokenType::Null)) {
            $this->advance();

            return new NullNode();
        }

        if ($token->is(TokenType::Void)) {
            $this->advance();

            return new VoidNode();
        }

        if ($token->is(TokenType::Dollar)) {
            return $this->parseExplicitVariablePath();
        }

        // Identifier — variable path or function call
        if ($token->is(TokenType::Identifier)) {
            return $this->parseVariablePath();
        }

        // Unexpected
        $this->syntaxError(
            sprintf('Unexpected %s in expression', $this->describeToken($token)),
            $token,
        );
    }

    /**
     * Parses a variable path (dot notation) with optional array subscripts.
     * e.g.: user.profile.name, items[0], items[key]
     */
    private function parseVariablePath(): VariableNode
    {
        $path = $this->advance()->value; // first identifier

        while ($this->peek()->is(TokenType::Dot)) {
            $this->advance(); // consume dot

            $next = $this->peek();
            if (! $next->is(TokenType::Identifier)) {
                break;
            }

            $path .= '.' . $this->advance()->value;
        }

        // Array subscript: items[0] or items['key']
        while ($this->peek()->is(TokenType::LBracket)) {
            $this->advance(); // consume [

            $path .= '[' . $this->parseSubscript() . ']';

            $this->consume(TokenType::RBracket);
        }

        return new VariableNode($path);
    }

    /** Keeps the quotes that tell a literal key from a variable of the same name. */
    private function parseSubscript(): string
    {
        $token = $this->advance();

        if ($token->is(TokenType::String)) {
            return "'" . $token->value . "'";
        }

        if (! $token->is(TokenType::Identifier)) {
            return $token->value;
        }

        $source = $token->value;

        while ($this->peek()->is(TokenType::Dot, TokenType::Colon)) {
            $separator = $this->advance();
            $next      = $this->peek();

            if (! $next->is(TokenType::Identifier)) {
                $this->syntaxError(sprintf(
                    'Expected an identifier after %s in a subscript',
                    $separator->type->describe(),
                ), $next);
            }

            $source .= $separator->value . $this->advance()->value;
        }

        return $source;
    }

    private function parseArrayLiteral(): ArrayNode
    {
        $this->consume(TokenType::LBracket);

        $items = [];
        if ($this->peek()->is(TokenType::RBracket)) {
            $this->consume(TokenType::RBracket);

            return new ArrayNode([]);
        }

        while (true) {
            $items[] = $this->parseAssignmentExpression();

            if ($this->peek()->is(TokenType::Comma)) {
                $this->advance();

                continue;
            }

            break;
        }

        $this->consume(TokenType::RBracket);

        return new ArrayNode($items);
    }

    private function parseExplicitVariablePath(): VariableNode
    {
        $this->consume(TokenType::Dollar);

        $path = $this->advance()->value;

        while ($this->peek()->is(TokenType::Dot, TokenType::Colon)) {
            $separator = $this->advance();
            $next      = $this->peek();

            if (! $next->is(TokenType::Identifier)) {
                $this->syntaxError(sprintf(
                    'Expected an identifier after %s in a variable path',
                    $separator->type->describe(),
                ), $next);
            }

            $path .= $separator->value . $this->advance()->value;
        }

        while ($this->peek()->is(TokenType::LBracket)) {
            $this->advance();

            $path .= '[' . $this->parseSubscript() . ']';

            $this->consume(TokenType::RBracket);
        }

        return new VariableNode($path);
    }

    private function parseModifierChain(AbstractNode $value): ModifierChainNode
    {
        $modifiers = [];

        while ($this->peek()->is(TokenType::Pipe)) {
            $this->advance(); // consume |

            // Modifier name
            $nameToken = $this->consumeModifierName();
            $params    = [];

            // Modifier params: | modifier:param1:param2
            while ($this->peek()->is(TokenType::Colon)) {
                $this->advance(); // consume :

                $params[] = $this->parsePrimary();
            }

            if ($this->peek()->is(TokenType::LParen)) {
                $params = $this->parseParenthesizedExpressionList();
            }

            $modifiers[] = new ModifierNode($nameToken->value, $params);
        }

        return new ModifierChainNode($value, $modifiers);
    }

    private function isCollectionOperator(Token $token): bool
    {
        return CollectionOperator::tryFromToken($token) instanceof CollectionOperator;
    }

    private function parseWhereCollectionOperator(): CollectionOperatorNode
    {
        $tokens = $this->parseParenthesizedTokenSlice();
        $alias  = null;

        foreach ($tokens as $index => $token) {
            if ($token->is(TokenType::Arrow)) {
                if ($index === 0) {
                    $this->syntaxError('Expected an identifier before "=>" in the where operator', $token);
                }

                $scopeToken = $tokens[$index - 1] ?? null;
                if (! $scopeToken instanceof Token || ! $scopeToken->is(TokenType::Identifier)) {
                    $this->syntaxError('Expected an identifier before "=>" in the where operator', $token);
                }

                $alias  = $scopeToken->value;
                $tokens = array_slice($tokens, $index + 1);

                break;
            }
        }

        return new CollectionOperatorNode(
            'where',
            [$this->parseTokenSlice($tokens)],
            scopeAlias: $alias,
        );
    }

    private function parseOrderByCollectionOperator(): CollectionOperatorNode
    {
        $arguments = array_map(function (array $tokens): CollectionSortArgument {
            [$field, $direction] = $this->parseCollectionFieldAndTail($tokens);

            return new CollectionSortArgument($field, $direction);
        }, $this->parseParenthesizedTokenGroups());

        return new CollectionOperatorNode('orderby', $arguments);
    }

    private function parseGroupByCollectionOperator(): CollectionOperatorNode
    {
        $arguments = array_map(function (array $tokens): CollectionGroupArgument {
            [$field, $alias] = $this->parseCollectionFieldAndAlias($tokens);

            return new CollectionGroupArgument($field, $alias);
        }, $this->parseParenthesizedTokenGroups());

        $valuesAlias = null;
        if ($this->peek()->is(TokenType::As)) {
            $this->advance();

            $valuesAlias = $this->parseCollectionAliasToken($this->advance());
        }

        return new CollectionOperatorNode('groupby', $arguments, $valuesAlias);
    }

    private function parseSingleCollectionArgument(): AbstractNode
    {
        return $this->parseTokenSlice($this->parseParenthesizedTokenSlice());
    }

    /**
     * @return list<AbstractNode>
     */
    private function parseParenthesizedExpressionList(): array
    {
        return array_map($this->parseTokenSlice(...), $this->parseParenthesizedTokenGroups());
    }

    /**
     * @return list<Token>
     */
    private function parseParenthesizedTokenSlice(): array
    {
        $groups = $this->parseParenthesizedTokenGroups();

        if (count($groups) !== 1) {
            $this->syntaxError('Expected a single parenthesized expression');
        }

        return $groups[0];
    }

    /**
     * @return list<list<Token>>
     */
    private function parseParenthesizedTokenGroups(): array
    {
        $this->consume(TokenType::LParen);

        /** @var list<list<Token>> $groups */
        $groups = [];
        $start  = $this->stream->position();
        $cursor = $start;
        $depth  = 0;

        while (true) {
            $token = $this->stream->at($cursor);

            if ($token->is(TokenType::Eof)) {
                $this->syntaxError('Unterminated parenthesized expression', $token);
            }

            if ($token->is(TokenType::LParen, TokenType::LBracket)) {
                $depth++;
            } elseif ($token->is(TokenType::RParen)) {
                if ($depth === 0) {
                    $groups[] = $this->stream->slice($start, $cursor - $start);

                    $this->stream->seek($cursor + 1);

                    /** @var list<list<Token>> $filtered */
                    $filtered = [];
                    foreach ($groups as $group) {
                        if ($group !== []) {
                            $filtered[] = $group;
                        }
                    }

                    return $filtered;
                }

                $depth--;
            } elseif ($token->is(TokenType::RBracket)) {
                $depth--;
            } elseif ($token->is(TokenType::Comma) && $depth === 0) {
                $groups[] = $this->stream->slice($start, $cursor - $start);
                $start    = $cursor + 1;
            }

            $cursor++;
        }
    }

    /**
     * @param list<Token> $tokens
     * @return array{AbstractNode, ?AbstractNode}
     */
    private function parseCollectionFieldAndTail(array $tokens): array
    {
        [$field, $consumed] = $this->parseLeadingExpressionFromTokens($tokens);

        $tail = array_slice($tokens, $consumed);

        return [$field, $tail !== [] ? $this->parseTokenSlice($tail) : null];
    }

    /**
     * @param list<Token> $tokens
     * @return array{AbstractNode, ?string}
     */
    private function parseCollectionFieldAndAlias(array $tokens): array
    {
        [$field, $consumed] = $this->parseLeadingExpressionFromTokens($tokens);

        $tail = array_slice($tokens, $consumed);

        if ($tail === []) {
            return [$field, null];
        }

        if (count($tail) !== 1) {
            $this->syntaxError('Invalid groupby alias');
        }

        return [$field, $this->parseCollectionAliasToken($tail[0])];
    }

    /**
     * @param list<Token> $tokens
     * @return array{AbstractNode, int}
     */
    private function parseLeadingExpressionFromTokens(array $tokens): array
    {
        $stream = $this->subStream($tokens);

        $node = $this->withStream($stream, $this->parsePipedExpression(...));

        return [$node, $stream->position()];
    }

    private function parseCollectionAliasToken(Token $token): string
    {
        if ($token->is(TokenType::String, TokenType::Identifier)) {
            return $token->value;
        }

        $this->syntaxError('Expected a collection alias name', $token);
    }

    private function consumeModifierName(): Token
    {
        $token = $this->peek();

        if ($token->is(
            TokenType::Identifier,
            TokenType::And,
            TokenType::Or,
            TokenType::Not,
            TokenType::As,
            TokenType::True,
            TokenType::False,
            TokenType::Null,
        )) {
            return $this->advance();
        }

        $this->syntaxError(
            sprintf('Expected a modifier name but found %s', $this->describeToken($token)),
            $token,
        );
    }

    private function looksLikeTag(string $raw): bool
    {
        $raw = trim($raw);

        // tag:method syntax
        if (preg_match('/^\w+:\w+/', $raw)) {
            return true;
        }

        // Identifier followed by key="value" param pattern
        return (bool) preg_match('/^\w+\s+(?::\$\w+|:?[\w-]+=)/', $raw);
    }

    private function parseDynamicParameterValue(string $value): AbstractNode
    {
        $value = trim($value);

        if (preg_match('/^\w+(?::\w+|\.\w+|\[[^]]+])*$/', $value) === 1) {
            return new VariableNode($value);
        }

        return $this->parseExpression($value);
    }

    private function makeStringNode(string $value): StringValueNode
    {
        $hasInterpolation = str_contains($value, '{') && str_contains($value, '}');

        $node = new StringValueNode($value, $hasInterpolation);

        $node->parts = $hasInterpolation ? $this->parseStringInterpolation($value) : [$value];

        return $node;
    }

    /**
     * Splits "Hello {name}, you are {age} years old" into parts.
     *
     * @return list<string|AbstractNode>
     */
    private function parseStringInterpolation(string $value): array
    {
        $parts  = [];
        $offset = 0;
        $length = strlen($value);

        while ($offset < $length) {
            $open = strpos($value, '{', $offset);
            if ($open === false) {
                $parts[] = substr($value, $offset);

                break;
            }

            if ($open > $offset) {
                $parts[] = substr($value, $offset, $open - $offset);
            }

            $close = strpos($value, '}', $open);
            if ($close === false) {
                $parts[] = substr($value, $open);

                break;
            }

            $expr    = substr($value, $open + 1, $close - $open - 1);
            $parts[] = $this->parseExpression($expr);
            $offset  = $close + 1;
        }

        return $parts;
    }

    /**
     * Operator infix binding power (precedence).
     * Returns null if the token is not a binary infix operator.
     */
    private function infixBp(Token $token): ?int
    {
        return match (true) {
            $token->is(TokenType::QQ, TokenType::QQQ) => 1,  // ?? ???  right-assoc
            $token->is(TokenType::Or, TokenType::Xor) => 2,  // ||, xor
            $token->is(TokenType::And) => 3,  // &&
            $token->is(
                TokenType::Spaceship,
                TokenType::EqEq,
                TokenType::NotEq,
                TokenType::EqEqEq,
                TokenType::NotEqEq,
            ) => 4,  // == != === !==
            $token->is(
                TokenType::Lt,
                TokenType::Gt,
                TokenType::LtEq,
                TokenType::GtEq,
            ) => 5,  // < > <= >=
            $token->is(TokenType::Dot) => 6,  // . string concat
            $token->is(TokenType::Plus, TokenType::Minus) => 7,  // + -
            $token->is(
                TokenType::Power,
                TokenType::Star,
                TokenType::Slash,
                TokenType::Percent,
            ) => 8,  // ** * / %
            $token->is(TokenType::Caret) => 9,  // ^ (right-assoc, but impl as left here)
            default => null,
        };
    }

    private function compoundAssignmentOperator(Token $token): string
    {
        return match ($token->type) {
            TokenType::PlusEquals => '+',
            TokenType::MinusEquals => '-',
            TokenType::StarEquals => '*',
            TokenType::SlashEquals => '/',
            TokenType::PercentEquals => '%',
            default => $this->syntaxError('Unsupported assignment operator: ' . $token->value, $token),
        };
    }

    private function peek(): Token
    {
        return $this->stream->peek();
    }

    private function advance(): Token
    {
        return $this->stream->advance();
    }

    private function consume(TokenType $type): void
    {
        $token = $this->peek();
        if (! $token->is($type)) {
            $this->syntaxError(sprintf(
                'Expected %s but found %s',
                $type->describe(),
                $this->describeToken($token),
            ), $token);
        }

        $this->advance();
    }

    /**
     * The single throw site for expression errors, so every one of them carries
     * the template line and the offending fragment.
     */
    private function syntaxError(string $message, ?Token $token = null): never
    {
        throw new AntlersSyntaxException($message, ($token ?? $this->peek())->line, $this->stream->source);
    }

    private function describeToken(Token $token): string
    {
        return $token->is(TokenType::Eof)
            ? 'end of expression'
            : sprintf('"%s"', $token->value);
    }
}
