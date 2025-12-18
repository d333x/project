<?php
function obfuscateCode($code) {
    $tokens = token_get_all($code);
    $output = '';
    foreach ($tokens as $token) {
        if (is_array($token)) {
            [$id, $text] = $token;
            switch ($id) {
                case T_VARIABLE:
                case T_STRING:
                    $output .= str_rot13($text);
                    break;
                default:
                    $output .= $text;
            }
        } else {
            $output .= $token;
        }
    }
    return str_rot13($output);
}