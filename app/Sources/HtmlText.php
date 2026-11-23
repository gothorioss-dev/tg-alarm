<?php

namespace App\Sources;

use Dom\Element;
use Dom\HTMLDocument;

final class HtmlText
{
    public static function fromHtml(string $html): string
    {
        $document = HTMLDocument::createFromString('<!DOCTYPE html><meta charset="utf-8"><body>'.$html, LIBXML_NOERROR, 'UTF-8');

        return self::fromElement($document->body);
    }

    public static function fromElement(Element $element): string
    {
        foreach (iterator_to_array($element->querySelectorAll('br')) as $br) {
            $br->replaceWith("\n");
        }
        foreach (iterator_to_array($element->querySelectorAll('p')) as $p) {
            $p->append("\n");
        }

        $text = str_replace("\u{00A0}", ' ', $element->textContent);

        return trim(preg_replace('/[ \t]*\R\s*/u', "\n", $text));
    }
}
