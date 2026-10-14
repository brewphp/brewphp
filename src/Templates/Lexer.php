<?php

declare(strict_types=1);

namespace BrewPHP\Templates;

/**
 * Splits template source into tokens, keeping track of line numbers.
 *
 * An opening `{{`, `{%` or `{#` without its closing delimiter is plain
 * text. `{% verbatim %}...{% endverbatim %}` is returned as one text
 * token, so its content is never parsed.
 */
final class Lexer
{
    private const CLOSERS = ['{{' => '}}', '{%' => '%}', '{#' => '#}'];

    private const TYPES = ['{{' => Token::VARIABLE, '{%' => Token::BLOCK, '{#' => Token::COMMENT];

    /**
     * @return list<Token>
     */
    public static function tokenize(string $source): array
    {
        $tokens = [];
        $position = 0;
        $line = 1;
        $length = strlen($source);

        while ($position < $length) {
            if (preg_match('/\{[{%#]/', $source, $match, PREG_OFFSET_CAPTURE, $position) !== 1) {
                break;
            }
            $start = $match[0][1];
            $opener = $match[0][0];
            $end = strpos($source, self::CLOSERS[$opener], $start + 2);
            if ($end === false) {
                break;
            }
            if ($start > $position) {
                $text = substr($source, $position, $start - $position);
                $tokens[] = new Token(Token::TEXT, $text, $line);
                $line += substr_count($text, "\n");
            }
            $raw = substr($source, $start, $end + 2 - $start);
            $token = new Token(self::TYPES[$opener], trim(substr($raw, 2, -2)), $line);
            $line += substr_count($raw, "\n");
            $position = $end + 2;

            if ($token->type === Token::BLOCK && $token->tagName() === 'verbatim') {
                $name = $token->tagArguments();
                $pattern = '/\{%\s*endverbatim' . ($name !== '' ? '\s+' . preg_quote($name, '/') : '') . '\s*%\}/';
                if (preg_match($pattern, $source, $close, PREG_OFFSET_CAPTURE, $position) !== 1) {
                    throw TemplateSyntaxError::atLine('Unclosed tag "verbatim": expected "endverbatim".', $token->line);
                }
                $text = substr($source, $position, $close[0][1] - $position);
                $tokens[] = new Token(Token::TEXT, $text, $line);
                $line += substr_count($text, "\n") + substr_count($close[0][0], "\n");
                $position = $close[0][1] + strlen($close[0][0]);
                continue;
            }
            $tokens[] = $token;
        }

        if ($position < $length) {
            $tokens[] = new Token(Token::TEXT, substr($source, $position), $line);
        }

        return $tokens;
    }
}
