<?php

declare(strict_types=1);

/**
 * Handles image uploads.
 */
class Upload
{
    private array $file;
    private string $type;
    private string $new_name;
    private string $original_name;
    private string $md5;
    public bool $success = false;

    private const ERRORS = [
        UPLOAD_ERR_PARTIAL     => 'The image was only partially uploaded.',
        UPLOAD_ERR_INI_SIZE    => 'The uploaded file exceeds the upload_max_filesize directive in php.ini.',
        UPLOAD_ERR_NO_FILE     => 'No file was uploaded.',
        UPLOAD_ERR_NO_TMP_DIR  => 'Missing a temporary directory.',
        UPLOAD_ERR_CANT_WRITE  => 'Failed to write image to disk.',
    ];

    private const VALID_TYPES = ['jpg', 'gif', 'png'];
    private const UNSAFE_CHARS = ['/', '<', '>', '"', "'", '%'];

    public const FULL_DIR  = '/img/';
    public const THUMB_DIR = '/thumbs/';

    /**
     * Constructor to validate and process the uploaded file.
     *
     * @param array $file An image from the $_FILES superglobal.
     * @throws Exception If the file upload fails validation.
     */
    public function __construct(array $file)
    {
        $this->file = $file;

        // Check for PHP-issued errors
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $error = self::ERRORS[$file['error']] ?? 'Unable to upload image.';
            throw new Exception($error);
        }

        // Check directory writability
        if (!is_writable(SITE_ROOT . self::FULL_DIR)) {
            throw new Exception('The image directory (' . self::FULL_DIR . ') is not writable.');
        }
        if (!is_writable(SITE_ROOT . self::THUMB_DIR)) {
            throw new Exception('The thumbnail directory (' . self::THUMB_DIR . ') is not writable.');
        }

        // Validate file name
        if (!preg_match('/(.+)\.([a-z0-9]+)$/i', $file['name'], $match)) {
            throw new Exception('The image has an invalid file name.');
        }

        $this->type = str_replace('jpeg', 'jpg', strtolower($match[2]));
        $this->md5 = md5_file($file['tmp_name']);
        $this->new_name = $_SERVER['REQUEST_TIME'] . mt_rand(99, 999999) . '.' . $this->type;
        $this->original_name = str_replace(self::UNSAFE_CHARS, '', $file['name']);
        $this->original_name = substr(trim($this->original_name), 0, 70);

        // Validate file type
        if (!in_array($this->type, self::VALID_TYPES, true)) {
            $last = array_pop(self::VALID_TYPES);
            throw new Exception('Only ' . implode(', ', self::VALID_TYPES) . ', and ' . $last . ' files are allowed.');
        }

        // Validate file size
        if ($file['size'] > MAX_IMAGE_SIZE) {
            throw new Exception('Uploaded images can be no greater than ' . round(MAX_IMAGE_SIZE / 1048576, 2) . ' MB.');
        }

        $this->success = true;
    }

    /**
     * Moves an image into the public dirs and associates it with a post.
     *
     * @param string $post_type Either 'reply' or 'topic'
     * @param int $post_id The ID of the post in the topic/replies table.
     * @throws Exception If the post type is invalid or database insertion fails.
     */
    public function move(string $post_type, int $post_id): void
    {
        global $db;

        if ($post_type !== 'topic' && $post_type !== 'reply') {
            throw new Exception('Invalid post type.');
        }

        // Check for existing identical image
        $res = $db->q('SELECT file_name FROM images WHERE md5 = ? AND deleted = 0 LIMIT 1', $this->md5);
        $previous_image = $res->fetchColumn();

        if ($previous_image) {
            $this->new_name = $previous_image;
        } else {
            $this->thumbnail();
            if (!move_uploaded_file($this->file['tmp_name'], SITE_ROOT . self::FULL_DIR . $this->new_name)) {
                throw new Exception('Failed to move uploaded file.');
            }
        }

        $db->q(
            'INSERT INTO images (file_name, original_name, md5, ' . $post_type . '_id) VALUES (?, ?, ?, ?)',
            $this->new_name,
            $this->original_name,
            $this->md5,
            $post_id
        );
    }

    /**
     * Generates a thumbnail for this upload.
     */
    private function thumbnail(): void
    {
        $dest = SITE_ROOT . self::THUMB_DIR . $this->new_name;
        $type = strtolower($this->type);

        $image = match ($type) {
            'jpg' => imagecreatefromjpeg($this->file['tmp_name']),
            'gif' => imagecreatefromgif($this->file['tmp_name']),
            'png' => imagecreatefrompng($this->file['tmp_name']),
            default => throw new Exception('Unsupported image type.'),
        };

        $width = imagesx($image);
        $height = imagesy($image);
        $max_dimensions = ($type === 'gif') ? MAX_GIF_DIMENSIONS : MAX_IMAGE_DIMENSIONS;

        if ($width > $max_dimensions || $height > $max_dimensions) {
            $percent = $max_dimensions / max($width, $height);
            $new_width = (int)($width * $percent);
            $new_height = (int)($height * $percent);
        } else {
            copy($this->file['tmp_name'], $dest);
            return;
        }

        if (IMAGEMAGICK) {
            $quality = ($type === 'gif') ? '75' : '90';
            exec('convert ' . escapeshellarg($this->file['tmp_name']) . ' -quality ' . $quality . ' -resize ' . $new_width . 'x' . $new_height . ' ' . escapeshellarg($dest));
            return;
        }

        // GD thumbnail creation
        $thumbnail = imagecreatetruecolor($new_width, $new_height);
        imagecopyresampled($thumbnail, $image, 0, 0, 0, 0, $new_width, $new_height, $width, $height);

        match ($type) {
            'jpg' => imagejpeg($thumbnail, $dest, 70),
            'gif' => imagegif($thumbnail, $dest),
            'png' => imagepng($thumbnail, $dest),
        };

        imagedestroy($thumbnail);
        imagedestroy($image);
    }
}