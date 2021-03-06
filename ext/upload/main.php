<?php declare(strict_types=1);

/**
 * Occurs when some data is being uploaded.
 */
class DataUploadEvent extends Event
{
    /** @var string */
    public $tmpname;
    /** @var array */
    public $metadata;
    /** @var string */
    public $hash;
    /** @var string */
    public $type = "";
    /** @var int */
    public $image_id = -1;
    /** @var int */
    public $replace_id = null;
    /** @var bool */
    public $handled = false;
    /** @var bool */
    public $merged = false;

    /**
     * Some data is being uploaded.
     * This should be caught by a file handler.
     * $metadata should contain at least "filename", "extension", "tags" and "source".
     */
    public function __construct(string $tmpname, array $metadata)
    {
        parent::__construct();
        global $config;

        assert(file_exists($tmpname));
        assert(is_string($metadata["filename"]));
        assert(is_array($metadata["tags"]));
        assert(is_string($metadata["source"]) || is_null($metadata["source"]));

        // DB limits to 64 char filenames
        $metadata['filename'] = substr($metadata['filename'], 0, 63);

        $this->metadata = $metadata;

        $this->set_tmpname($tmpname);

        if ($config->get_bool("upload_use_mime")) {
            $filetype = get_extension_for_file($tmpname);
        }

        if (empty($filetype)) {
            if (array_key_exists('extension', $metadata) && !empty($metadata['extension'])) {
                $filetype = strtolower($metadata['extension']);
            } else {
                throw new UploadException("Could not determine extension for file " . $metadata["filename"]);
            }
        }

        if (empty($filetype)) {
            throw new UploadException("Could not determine extension for file " . $metadata["filename"]);
        }

        $this->set_type($filetype);
    }

    public function set_type(String $type)
    {
        $this->type = strtolower($type);
        $this->metadata["extension"] = $this->type;
    }

    public function set_tmpname(String $tmpname)
    {
        $this->tmpname = $tmpname;
        $this->metadata['hash'] = md5_file($tmpname);
        $this->metadata['size'] = filesize($tmpname);
        // useful for most file handlers, so pull directly into fields
        $this->hash = $this->metadata['hash'];
    }
}

class UploadException extends SCoreException
{
}

/**
 * Main upload class.
 * All files that are uploaded to the site are handled through this class.
 * This also includes transloaded files as well.
 */
class Upload extends Extension
{
    /** @var UploadTheme */
    protected $theme;

    /** @var bool */
    public $is_full;

    /**
     * Early, so it can stop the DataUploadEvent before any data handlers see it.
     */
    public function get_priority(): int
    {
        return 40;
    }

    public function onInitExt(InitExtEvent $event)
    {
        global $config;
        $config->set_default_int('upload_count', 3);
        $config->set_default_int('upload_size', parse_shorthand_int('1MB'));
        $config->set_default_int('upload_min_free_space', parse_shorthand_int('100MB'));
        $config->set_default_bool('upload_tlsource', true);
        $config->set_default_bool('upload_use_mime', false);

        $this->is_full = false;

        $min_free_space = $config->get_int("upload_min_free_space");
        if ($min_free_space > 0) {
            // SHIT: fucking PHP "security" measures -_-;;;
            $img_path = realpath("./images/");
            if ($img_path) {
                $free_num = @disk_free_space($img_path);
                if ($free_num !== false) {
                    $this->is_full = $free_num < $min_free_space;
                }
            }
        }
    }

    public function onSetupBuilding(SetupBuildingEvent $event)
    {
        $tes = [];
        $tes["Disabled"] = "none";
        if (function_exists("curl_init")) {
            $tes["cURL"] = "curl";
        }
        $tes["fopen"] = "fopen";
        $tes["WGet"] = "wget";

        $sb = new SetupBlock("Upload");
        $sb->position = 10;
        // Output the limits from PHP so the user has an idea of what they can set.
        $sb->add_int_option("upload_count", "Max uploads: ");
        $sb->add_label("<i>PHP Limit = " . ini_get('max_file_uploads') . "</i>");
        $sb->add_shorthand_int_option("upload_size", "<br/>Max size per file: ");
        $sb->add_label("<i>PHP Limit = " . ini_get('upload_max_filesize') . "</i>");
        $sb->add_choice_option("transload_engine", $tes, "<br/>Transload: ");
        $sb->add_bool_option("upload_tlsource", "<br/>Use transloaded URL as source if none is provided: ");
        $sb->add_bool_option("upload_use_mime", "<br/>Use mime type to determine file types: ");
        $event->panel->add_block($sb);
    }


    public function onPageNavBuilding(PageNavBuildingEvent $event)
    {
        global $user;
        if ($user->can(Permissions::CREATE_IMAGE)) {
            $event->add_nav_link("upload", new Link('upload'), "Upload");
        }
    }

    public function onPageSubNavBuilding(PageSubNavBuildingEvent $event)
    {
        if ($event->parent=="upload") {
            if (class_exists("Wiki")) {
                $event->add_nav_link("upload_guidelines", new Link('wiki/upload_guidelines'), "Guidelines");
            }
        }
    }

    public function onDataUpload(DataUploadEvent $event)
    {
        global $config;
        if ($this->is_full) {
            throw new UploadException("Upload failed; disk nearly full");
        }
        if (filesize($event->tmpname) > $config->get_int('upload_size')) {
            $size = to_shorthand_int(filesize($event->tmpname));
            $limit = to_shorthand_int($config->get_int('upload_size'));
            throw new UploadException("File too large ($size > $limit)");
        }
    }

    public function onPageRequest(PageRequestEvent $event)
    {
        global $cache, $page, $user;

        if ($user->can(Permissions::CREATE_IMAGE)) {
            if ($this->is_full) {
                $this->theme->display_full($page);
            } else {
                $this->theme->display_block($page);
            }
        }

        if ($event->page_matches("upload/replace")) {
            // check if the user is an administrator and can upload files.
            if (!$user->can(Permissions::REPLACE_IMAGE)) {
                $this->theme->display_permission_denied();
            } else {
                if ($this->is_full) {
                    throw new UploadException("Can not replace Image: disk nearly full");
                }

                // Try to get the image ID
                if ($event->count_args() >= 1) {
                    $image_id = int_escape($event->get_arg(0));
                } elseif (isset($_POST['image_id'])) {
                    $image_id = int_escape($_POST['image_id']);
                } else {
                    throw new UploadException("Can not replace Image: No valid Image ID given.");
                }

                $image_old = Image::by_id($image_id);
                if (is_null($image_old)) {
                    throw new UploadException("Can not replace Image: No image with ID $image_id");
                }

                $source = $_POST['source'] ?? null;

                if (!empty($_POST["url"])) {
                    $ok = $this->try_transload($_POST["url"], [], $source, $image_id);
                    $cache->delete("thumb-block:{$image_id}");
                    $this->theme->display_upload_status($page, $ok);
                } elseif (count($_FILES) > 0) {
                    $ok = $this->try_upload($_FILES["data"], [], $source, $image_id);
                    $cache->delete("thumb-block:{$image_id}");
                    $this->theme->display_upload_status($page, $ok);
                } elseif (!empty($_GET['url'])) {
                    $ok = $this->try_transload($_GET['url'], [], $source, $image_id);
                    $cache->delete("thumb-block:{$image_id}");
                    $this->theme->display_upload_status($page, $ok);
                } else {
                    $this->theme->display_replace_page($page, $image_id);
                }
            }
        } elseif ($event->page_matches("upload")) {
            if (!$user->can(Permissions::CREATE_IMAGE)) {
                $this->theme->display_permission_denied();
            } else {
                /* Regular Upload Image */
                if (count($_FILES) + count($_POST) > 0) {
                    $ok = true;
                    foreach ($_FILES as $name => $file) {
                        $tags = $this->tags_for_upload_slot(int_escape(substr($name, 4)));
                        $source = isset($_POST['source']) ? $_POST['source'] : null;
                        $ok = $this->try_upload($file, $tags, $source) && $ok;
                    }
                    foreach ($_POST as $name => $value) {
                        if (substr($name, 0, 3) == "url" && strlen($value) > 0) {
                            $tags = $this->tags_for_upload_slot(int_escape(substr($name, 3)));
                            $source = isset($_POST['source']) ? $_POST['source'] : $value;
                            $ok = $this->try_transload($value, $tags, $source) && $ok;
                        }
                    }

                    $this->theme->display_upload_status($page, $ok);
                } elseif (!empty($_GET['url'])) {
                    $url = $_GET['url'];
                    $source = isset($_GET['source']) ? $_GET['source'] : $url;
                    $tags = ['tagme'];
                    if (!empty($_GET['tags']) && $_GET['tags'] != "null") {
                        $tags = Tag::explode($_GET['tags']);
                    }

                    $ok = $this->try_transload($url, $tags, $source);
                    $this->theme->display_upload_status($page, $ok);
                } else {
                    if ($this->is_full) {
                        $this->theme->display_full($page);
                    } else {
                        $this->theme->display_page($page);
                    }
                }
            }
        }
    }

    private function tags_for_upload_slot(int $id): array
    {
        $post_tags = isset($_POST["tags"]) ? $_POST["tags"] : "";

        if (isset($_POST["tags$id"])) {
            # merge then explode, not explode then merge - else
            # one of the merges may create a surplus "tagme"
            $tags = Tag::explode($post_tags . " " . $_POST["tags$id"]);
        } else {
            $tags = Tag::explode($post_tags);
        }
        return $tags;
    }

    /**
     * Returns a descriptive error message for the specified PHP error code.
     *
     * This is a helper function based on the one from the online PHP Documentation
     * which is licensed under Creative Commons Attribution 3.0 License
     *
     * TODO: Make these messages user/admin editable
     */
    private function upload_error_message(int $error_code): string
    {
        switch ($error_code) {
            case UPLOAD_ERR_INI_SIZE:
                return 'The uploaded file exceeds the upload_max_filesize directive in php.ini';
            case UPLOAD_ERR_FORM_SIZE:
                return 'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form';
            case UPLOAD_ERR_PARTIAL:
                return 'The uploaded file was only partially uploaded';
            case UPLOAD_ERR_NO_FILE:
                return 'No file was uploaded';
            case UPLOAD_ERR_NO_TMP_DIR:
                return 'Missing a temporary folder';
            case UPLOAD_ERR_CANT_WRITE:
                return 'Failed to write file to disk';
            case UPLOAD_ERR_EXTENSION:
                return 'File upload stopped by extension';
            default:
                return 'Unknown upload error';
        }
    }

    /**
     * Handle an upload.
     * #param string[] $file
     * #param string[] $tags
     */
    private function try_upload(array $file, array $tags, ?string $source = null, ?int $replace_id = null): bool
    {
        global $page;

        if (empty($source)) {
            $source = null;
        }

        $ok = true;

        // blank file boxes cause empty uploads, no need for error message
        if (!empty($file['name'])) {
            try {
                // check if the upload was successful
                if ($file['error'] !== UPLOAD_ERR_OK) {
                    throw new UploadException($this->upload_error_message($file['error']));
                }

                $pathinfo = pathinfo($file['name']);
                $metadata = [];
                $metadata['filename'] = $pathinfo['basename'];
                if (array_key_exists('extension', $pathinfo)) {
                    $metadata['extension'] = $pathinfo['extension'];
                }
                $metadata['tags'] = $tags;
                $metadata['source'] = $source;

                $event = new DataUploadEvent($file['tmp_name'], $metadata);
                $event->replace_id = $replace_id;
                send_event($event);
                if ($event->image_id == -1) {
                    throw new UploadException("File type not supported: " . $metadata['extension']);
                }
                $page->add_http_header("X-Shimmie-Image-ID: " . $event->image_id);
            } catch (UploadException $ex) {
                $this->theme->display_upload_error(
                    $page,
                    "Error with " . html_escape($file['name']),
                    $ex->getMessage()
                );
                $ok = false;
            }
        }

        return $ok;
    }

    private function try_transload(string $url, array $tags, string $source = null, ?int $replace_id = null): bool
    {
        global $page, $config, $user;

        $ok = true;

        // Checks if user is admin > check if you want locked.
        if ($user->can(Permissions::EDIT_IMAGE_LOCK) && !empty($_GET['locked'])) {
            $locked = bool_escape($_GET['locked']);
        }

        // Checks if url contains rating, also checks if the rating extension is enabled.
        if ($config->get_string("transload_engine", "none") != "none" && Extension::is_enabled(RatingsInfo::KEY) && !empty($_GET['rating'])) {
            // Rating event will validate that this is s/q/e/u
            $rating = strtolower($_GET['rating']);
            $rating = $rating[0];
        } else {
            $rating = "";
        }

        $tmp_filename = tempnam(ini_get('upload_tmp_dir'), "shimmie_transload");

        // transload() returns Array or Bool, depending on the transload_engine.
        $headers = transload($url, $tmp_filename);

        $s_filename = is_array($headers) ? findHeader($headers, 'Content-Disposition') : null;
        $h_filename = ($s_filename ? preg_replace('/^.*filename="([^ ]+)"/i', '$1', $s_filename) : null);
        $filename = $h_filename ?: basename($url);

        if (!$headers) {
            $this->theme->display_upload_error(
                $page,
                "Error with " . html_escape($filename),
                "Error reading from " . html_escape($url)
            );
            return false;
        }

        if (filesize($tmp_filename) == 0) {
            $this->theme->display_upload_error(
                $page,
                "Error with " . html_escape($filename),
                "No data found -- perhaps the site has hotlink protection?"
            );
            $ok = false;
        } else {
            $pathinfo = pathinfo($url);
            $metadata = [];
            $metadata['filename'] = $filename;
            $metadata['tags'] = $tags;
            $metadata['source'] = (($url == $source) && !$config->get_bool('upload_tlsource') ? "" : $source);

            $ext = false;
            if (is_array($headers)) {
                $ext = get_extension(findHeader($headers, 'Content-Type'));
            }
            if ($ext === false) {
                $ext = $pathinfo['extension'];
            }
            $metadata['extension'] = $ext;

            /* check for locked > adds to metadata if it has */
            if (!empty($locked)) {
                $metadata['locked'] = $locked ? "on" : "";
            }

            /* check for rating > adds to metadata if it has */
            if (!empty($rating)) {
                $metadata['rating'] = $rating;
            }

            try {
                $event = new DataUploadEvent($tmp_filename, $metadata);
                $event->replace_id = $replace_id;
                send_event($event);
                if ($event->image_id == -1) {
                    throw new UploadException("File type not supported: " . $metadata['extension']);
                }
            } catch (UploadException $ex) {
                $this->theme->display_upload_error(
                    $page,
                    "Error with " . html_escape($url),
                    $ex->getMessage()
                );
                $ok = false;
            }
        }

        unlink($tmp_filename);

        return $ok;
    }
}
