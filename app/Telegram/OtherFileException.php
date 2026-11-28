<?php

namespace App\Telegram;

use App\Sources\SourceException;

/** Telegram gives a file that the post no longer has: it keeps the file of a link after the post is edited. */
final class OtherFileException extends SourceException {}
