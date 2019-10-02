<?php

/*
 * Name: Handle MP3
 * Author: Shish <webmaster@shishnet.org>
 * Description: Handle MP3 files
 */

class MP3FileHandlerInfo extends ExtensionInfo
{
    public const KEY = "handle_mp3";

    public $key = self::KEY;
    public $name = "Handle MP3";
    public $url = self::SHIMMIE_URL;
    public $authors = self::SHISH_AUTHOR;
    public $description = "Handle MP3 files";
}