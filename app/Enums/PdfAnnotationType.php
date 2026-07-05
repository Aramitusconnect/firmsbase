<?php

namespace App\Enums;

/**
 * PdfAnnotationType — only meaningful when a pdf_view_events row has
 * action = AnnotationAdded. Minimal, realistic set; no free-form
 * annotation "tool" taxonomy is invented since no real viewer exists
 * yet to define one.
 */
enum PdfAnnotationType: string
{
    case Highlight = 'highlight';
    case Note = 'note';
    case Underline = 'underline';
}
