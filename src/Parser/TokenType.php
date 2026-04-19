<?php

declare(strict_types=1);

namespace Bugo\Antlers\Parser;

enum TokenType: string
{
    // Identifiers and paths
    case Dollar     = 'T_DOLLAR';     // $ explicit variable prefix
    case Identifier = 'T_IDENTIFIER'; // variable names, tag names, keywords
    case Dot        = 'T_DOT';        // . path separator or string concat op
    case Colon      = 'T_COLON';      // : tag:method separator or modifier param
    case Pipe       = 'T_PIPE';       // | modifier separator

    // Literals
    case String = 'T_STRING'; // 'text' or "text"
    case Number = 'T_NUMBER'; // 42, 3.14
    case True   = 'T_TRUE';   // true
    case False  = 'T_FALSE';  // false
    case Null   = 'T_NULL';   // null
    case Void   = 'T_VOID';   // void

    // Arithmetic operators
    case Plus          = 'T_PLUS';          // +
    case PlusEquals    = 'T_PLUSEQUALS';    // +=
    case Minus         = 'T_MINUS';         // -
    case MinusEquals   = 'T_MINUSEQUALS';   // -=
    case Star          = 'T_STAR';          // *
    case StarEquals    = 'T_STAREQUALS';    // *=
    case Power         = 'T_POWER';         // **
    case Slash         = 'T_SLASH';         // /
    case SlashEquals   = 'T_SLASHEQUALS';   // /=
    case Percent       = 'T_PERCENT';       // %
    case PercentEquals = 'T_PERCENTEQUALS'; // %=
    case Caret         = 'T_CARET';         // ^

    // Comparison operators
    case Spaceship = 'T_SPACESHIP'; // <=>
    case EqEq      = 'T_EQEQ';      // ==
    case NotEq     = 'T_NOTEQ';     // !=
    case EqEqEq    = 'T_EQEQEQ';    // ===
    case NotEqEq   = 'T_NOTEQEQ';   // !==
    case Lt        = 'T_LT';        // <
    case Gt        = 'T_GT';        // >
    case LtEq      = 'T_LTEQ';      // <=
    case GtEq      = 'T_GTEQ';      // >=

    // Logical operators
    case And = 'T_AND'; // && or 'and'
    case Or  = 'T_OR';  // || or 'or'
    case Xor = 'T_XOR'; // xor
    case Not = 'T_NOT'; // ! or 'not'

    // Assignment / null coalesce
    case Equals   = 'T_EQUALS';   // =
    case Question = 'T_QUESTION'; // ?
    case QEquals  = 'T_QEQUALS';  // ?=
    case QQ       = 'T_QQ';       // ??

    // Grouping and array access
    case LParen   = 'T_LPAREN';   // (
    case RParen   = 'T_RPAREN';   // )
    case LBracket = 'T_LBRACKET'; // [
    case RBracket = 'T_RBRACKET'; // ]

    // Structure
    case Comma     = 'T_COMMA';     // ,
    case Semicolon = 'T_SEMICOLON'; // ;
    case Arrow     = 'T_ARROW';     // =>
    case As        = 'T_AS';        // as keyword

    // Special
    case Eof = 'T_EOF';
}
