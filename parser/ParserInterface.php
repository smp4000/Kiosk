<?php
declare(strict_types=1);

interface ParserInterface
{
    public static function canParse(string $pdfPath, string $rawText): bool;

    public static function parse(string $pdfPath, string $rawText): array;
}
