<?php

declare(strict_types=1);

namespace Bugo\Antlers\Parser;

use Bugo\Antlers\Exceptions\AntlersSyntaxException;
use Bugo\Antlers\Nodes\AbstractNode;
use Bugo\Antlers\Nodes\AntlersNode;
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
use Bugo\Antlers\Nodes\UnaryOpNode;
use Bugo\Antlers\Nodes\VariableNode;

/**
 * Stage 3: Parses AntlersNode raw content + children into typed AST nodes.
 *
 * Uses a Pratt (top-down operator precedence) parser for expressions.
 */
final class LanguageParser
{
    private readonly Lexer $lexer;

    /** @var Token[] */
    private array $tokens = [];

    private int $pos = 0;

    public function __construct()
    {
        $this->lexer = new Lexer();
    }

    /**
     * Parse a single AntlersNode into a typed AST node.
     * Children (for block tags) are processed recursively.
     */
    public function parseNode(AntlersNode $node): AbstractNode
    {
        $raw = $node->rawContent;

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
            if ($this->isForeachLoopSyntax($raw)) {
                return $this->parseForeachNode($node);
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

        if (in_array($node->name, [
            'switch',
            'markdown',
            'scope',
            'loop',
            'section',
            'yield',
            'slot',
            'stack',
            'push',
            'prepend',
            'once',
            'partial',
            'layout',
            'dump',
            'svg',
            'increment',
        ], strict: true)) {
            return $this->parseTagNode($node);
        }

        if (str_starts_with($node->name, 'scope:')
            || str_starts_with($node->name, 'section:')
            || str_starts_with($node->name, 'yield:')
            || str_starts_with($node->name, 'slot:')
            || str_starts_with($node->name, 'stack:')
            || str_starts_with($node->name, 'push:')
            || str_starts_with($node->name, 'prepend:')
            || str_starts_with($node->name, 'once:')
            || str_starts_with($node->name, 'layout:')
            || str_starts_with($node->name, 'markdown:')
        ) {
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

    private function parseForeachNode(AntlersNode $blockNode): LoopNode
    {
        // foreach items as item
        // foreach items as key => value
        $raw = trim(substr($blockNode->rawContent, strlen('foreach')));

        // Extract "items as ..." part
        if (! preg_match('/^(.+?)\s+as\s+(.+)$/i', $raw, $m)) {
            throw new AntlersSyntaxException(
                "Invalid foreach syntax: {{ $blockNode->rawContent }}",
                $blockNode->line,
            );
        }

        $iterableExpr = $this->parseExpression(trim($m[1]));
        $aliasPart    = trim($m[2]);

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
                "Invalid for syntax: {{ $blockNode->rawContent }}",
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
            throw new AntlersSyntaxException("Invalid set syntax: {{ $raw }}");
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
            if ($pos >= $length) {
                break;
            }

            // Read key
            $keyStart  = $pos;
            $isDynamic = $raw[$pos] === ':';

            if ($isDynamic) {
                $pos++;
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
        $input        = trim($input);
        $this->tokens = $this->lexer->tokenize($input);
        $this->pos    = 0;

        return $this->parseStatementSequence();
    }

    private function parseStatementSequence(TokenType $terminator = TokenType::Eof): AbstractNode
    {
        if ($this->peek()->is($terminator, TokenType::Eof)) {
            throw new AntlersSyntaxException('Expected expression before statement terminator');
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

            throw new AntlersSyntaxException(
                "Unexpected token {$token->type->value} ('$token->value') in expression",
            );
        }

        if (count($statements) === 1) {
            return $statements[0];
        }

        return new SequenceNode($statements);
    }

    private function parseAssignmentExpression(): AbstractNode
    {
        $expr = $this->parsePipedExpression();

        if (! $this->peek()->is(TokenType::Equals)) {
            return $expr;
        }

        if (! $expr instanceof VariableNode) {
            throw new AntlersSyntaxException('Assignment target must be a variable path');
        }

        $this->consume(TokenType::Equals);

        return new AssignmentNode($expr->path, $this->parseAssignmentExpression());
    }

    private function parsePipedExpression(): AbstractNode
    {
        $expr = $this->parseGatekeeperExpression();

        if ($this->peek()->is(TokenType::Pipe)) {
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

        return new GatekeeperNode($expr, $this->parsePipedExpression());
    }

    private function parseCollectionExpression(): AbstractNode
    {
        $expr = $this->parseTernaryExpression();

        $operations = [];

        while ($this->isCollectionOperator($this->peek())) {
            $operator = strtolower($this->advance()->value);

            $operations[] = match ($operator) {
                'merge'   => new CollectionOperatorNode($operator, [$this->parseTernaryExpression()]),
                'where'   => $this->parseWhereCollectionOperator(),
                'take',
                'skip',
                'pluck'   => new CollectionOperatorNode($operator, [$this->parseSingleCollectionArgument()]),
                'orderby' => $this->parseOrderByCollectionOperator(),
                'groupby' => $this->parseGroupByCollectionOperator(),
                default   => throw new AntlersSyntaxException("Unsupported collection operator: $operator"),
            };
        }

        if ($operations === []) {
            return $expr;
        }

        return new CollectionOperationNode($expr, $operations);
    }

    private function parseTernaryExpression(): AbstractNode
    {
        $expr = $this->parseExpr(0);

        if (! $this->peek()->is(TokenType::Question)) {
            return $expr;
        }

        $this->consume(TokenType::Question);

        $trueBranch = $this->parseTokenSlice($this->collectTernaryBranchTokens());

        $this->consume(TokenType::Colon);

        $falseBranch = $this->parsePipedExpression();

        return new TernaryNode($expr, $trueBranch, $falseBranch);
    }

    /**
     * @return Token[]
     */
    private function collectTernaryBranchTokens(): array
    {
        $start        = $this->pos;
        $cursor       = $this->pos;
        $parenDepth   = 0;
        $bracketDepth = 0;
        $ternaryDepth = 0;

        while (true) {
            $token = $this->tokens[$cursor] ?? new Token(TokenType::Eof, '');

            if ($token->is(TokenType::Eof)) {
                throw new AntlersSyntaxException('Unterminated ternary expression');
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

        $this->pos = $cursor;

        return array_slice($this->tokens, $start, $cursor - $start);
    }

    /**
     * @param Token[] $tokens
     */
    private function parseTokenSlice(array $tokens): AbstractNode
    {
        $previousTokens = $this->tokens;
        $previousPos    = $this->pos;

        $this->tokens = [...$tokens, new Token(TokenType::Eof, '')];
        $this->pos    = 0;

        try {
            return $this->parseStatementSequence();
        } finally {
            $this->tokens = $previousTokens;
            $this->pos    = $previousPos;
        }
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

            // Null coalesce — right-associative
            if ($op->is(TokenType::QQ)) {
                $this->advance();

                $right = $this->parseExpr($bp - 1);
                $left  = new NullCoalesceNode($left, $right);

                continue;
            }

            // Stop before pipe, question, colon, comma, semicolon, rparen, rbracket (handled elsewhere)
            if ($op->is(
                TokenType::Pipe,
                TokenType::Question,
                TokenType::Colon,
                TokenType::Comma,
                TokenType::Semicolon,
                TokenType::RParen,
                TokenType::RBracket,
            )) {
                break;
            }

            $this->advance();

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

        if ($token->is(TokenType::Dollar)) {
            return $this->parseExplicitVariablePath();
        }

        // Identifier — variable path or function call
        if ($token->is(TokenType::Identifier)) {
            return $this->parseVariablePath();
        }

        // Unexpected
        throw new AntlersSyntaxException(
            "Unexpected token $token in expression",
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

            $indexToken = $this->advance();

            $path .= '[' . $indexToken->value . ']';

            $this->consume(TokenType::RBracket);
        }

        return new VariableNode($path);
    }

    private function parseExplicitVariablePath(): VariableNode
    {
        $this->consume(TokenType::Dollar);

        $path = $this->advance()->value;

        while ($this->peek()->is(TokenType::Dot, TokenType::Colon)) {
            $separator = $this->advance();
            $next      = $this->peek();

            if (! $next->is(TokenType::Identifier)) {
                throw new AntlersSyntaxException(
                    "Expected identifier after {$separator->type->value} in variable path",
                );
            }

            $path .= $separator->value . $this->advance()->value;
        }

        while ($this->peek()->is(TokenType::LBracket)) {
            $this->advance();

            $indexToken = $this->advance();

            $path .= '[' . $indexToken->value . ']';

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
        return $token->is(TokenType::Identifier)
            && in_array(strtolower($token->value), [
                'merge',
                'where',
                'take',
                'skip',
                'pluck',
                'orderby',
                'groupby',
            ], true);
    }

    private function parseWhereCollectionOperator(): CollectionOperatorNode
    {
        $tokens = $this->parseParenthesizedTokenSlice();
        $alias  = null;

        foreach ($tokens as $index => $token) {
            if ($token->is(TokenType::Arrow)) {
                if ($index === 0) {
                    throw new AntlersSyntaxException('Expected identifier before => in where operator');
                }

                $scopeToken = $tokens[$index - 1] ?? null;
                if (! $scopeToken instanceof Token || ! $scopeToken->is(TokenType::Identifier)) {
                    throw new AntlersSyntaxException('Expected identifier before => in where operator');
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
            throw new AntlersSyntaxException('Expected a single parenthesized expression');
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
        $start  = $this->pos;
        $cursor = $this->pos;
        $depth  = 0;

        while (true) {
            $token = $this->tokens[$cursor] ?? new Token(TokenType::Eof, '');

            if ($token->is(TokenType::Eof)) {
                throw new AntlersSyntaxException('Unterminated parenthesized expression');
            }

            if ($token->is(TokenType::LParen, TokenType::LBracket)) {
                $depth++;
            } elseif ($token->is(TokenType::RParen)) {
                if ($depth === 0) {
                    $groups[] = array_values(array_slice($this->tokens, $start, $cursor - $start));

                    $this->pos = $cursor + 1;

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
                $groups[] = array_values(array_slice($this->tokens, $start, $cursor - $start));
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
        if ($tokens === []) {
            throw new AntlersSyntaxException('Expected collection operator argument');
        }

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
        if ($tokens === []) {
            throw new AntlersSyntaxException('Expected groupby argument');
        }

        [$field, $consumed] = $this->parseLeadingExpressionFromTokens($tokens);

        $tail = array_slice($tokens, $consumed);

        if ($tail === []) {
            return [$field, null];
        }

        if (count($tail) !== 1) {
            throw new AntlersSyntaxException('Invalid groupby alias');
        }

        return [$field, $this->parseCollectionAliasToken($tail[0])];
    }

    /**
     * @param list<Token> $tokens
     * @return array{AbstractNode, int}
     */
    private function parseLeadingExpressionFromTokens(array $tokens): array
    {
        $previousTokens = $this->tokens;
        $previousPos    = $this->pos;

        $this->tokens = [...$tokens, new Token(TokenType::Eof, '')];
        $this->pos    = 0;

        try {
            $node     = $this->parsePipedExpression();
            $consumed = $this->pos;

            return [$node, $consumed];
        } finally {
            $this->tokens = $previousTokens;
            $this->pos    = $previousPos;
        }
    }

    private function parseCollectionAliasToken(Token $token): string
    {
        if ($token->is(TokenType::String, TokenType::Identifier)) {
            return $token->value;
        }

        throw new AntlersSyntaxException('Expected collection alias name');
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

        throw new AntlersSyntaxException(
            "Expected modifier name but got {$token->type->value} ('$token->value')",
        );
    }

    private function looksLikeTag(string $raw): bool
    {
        $raw = trim($raw);

        // tag:method syntax
        if (preg_match('/^\w+:\w+/', $raw)) {
            return true;
        }

        // Known built-in tags
        $knownTags = ['partial', 'cache', 'markdown', 'scope', 'set', 'increment', 'slot'];
        $firstWord = strtolower((string) preg_replace('/[\s:].*/s', '', $raw));
        if (in_array($firstWord, $knownTags, strict: true)) {
            return false; // handled separately
        }

        // Identifier followed by key="value" param pattern
        return (bool) preg_match('/^\w+\s+[\w-]+=/', $raw);
    }

    private function isForeachLoopSyntax(string $raw): bool
    {
        $payload = trim(substr($raw, strlen('foreach')));

        return $payload !== '' && preg_match('/^(.+?)\s+as\s+(.+)$/i', $payload) === 1;
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
            $token->is(TokenType::QQ) => 1,  // ??  right-assoc
            $token->is(TokenType::Or) => 2,  // ||
            $token->is(TokenType::And) => 3,  // &&
            $token->is(
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
                TokenType::Star,
                TokenType::Slash,
                TokenType::Percent,
            ) => 8,  // * / %
            $token->is(TokenType::Caret) => 9,  // ^ (right-assoc, but impl as left here)
            default => null,
        };
    }

    private function peek(): Token
    {
        return $this->tokens[$this->pos] ?? new Token(TokenType::Eof, '');
    }

    private function advance(): Token
    {
        $token = $this->tokens[$this->pos] ?? new Token(TokenType::Eof, '');

        $this->pos++;

        return $token;
    }

    private function consume(TokenType $type): void
    {
        $token = $this->peek();
        if (! $token->is($type)) {
            throw new AntlersSyntaxException(
                "Expected $type->value but got {$token->type->value} ('$token->value')",
            );
        }

        $this->advance();
    }
}
