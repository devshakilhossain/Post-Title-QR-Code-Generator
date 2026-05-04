<?php

declare(strict_types=1);

namespace RSQRCodeGenerator;

use Endroid\QrCode\Color\Color;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Logo\Logo;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Writer\SvgWriter;
use WP_Post;

use function add_action;
use function add_meta_box;
use function admin_url;
use function check_ajax_referer;
use function current_user_can;
use function esc_attr;
use function esc_html;
use function esc_url;
use function file_exists;
use function get_attached_file;
use function get_edit_post_link;
use function get_post;
use function get_post_meta;
use function get_the_title;
use function is_wp_error;
use function load_plugin_textdomain;
use function plugin_basename;
use function sanitize_file_name;
use function sanitize_text_field;
use function sprintf;
use function time;
use function update_post_meta;
use function wp_check_filetype;
use function wp_delete_attachment;
use function wp_enqueue_script;
use function wp_enqueue_style;
use function wp_generate_attachment_metadata;
use function wp_insert_attachment;
use function wp_json_encode;
use function wp_localize_script;
use function wp_mkdir_p;
use function wp_nonce_field;
use function wp_send_json_error;
use function wp_send_json_success;
use function wp_upload_dir;
use function wp_update_attachment_metadata;

final class Plugin
{
    private const META_ATTACHMENT_ID = '_rs_qr_code_attachment_id';
    private const META_TITLE_HASH = '_rs_qr_code_title_hash';
    private const NONCE_ACTION = 'rs_qr_code_generator';
    private const AJAX_ACTION = 'rs_qr_generate_post_qr';

    private static ?self $instance = null;

    public static function instance(): self
    {
        if (! self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function boot(): void
    {
        add_action('init', [$this, 'load_textdomain']);
        add_action('add_meta_boxes_post', [$this, 'add_post_meta_box']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('wp_ajax_' . self::AJAX_ACTION, [$this, 'ajax_generate_qr_code']);
    }

    public function load_textdomain(): void
    {
        load_plugin_textdomain('rs-qr-code-generator', false, dirname(plugin_basename(RS_QR_CODE_GENERATOR_FILE)) . '/languages');
    }

    public function add_post_meta_box(): void
    {
        add_meta_box(
            'rs-qr-code-generator',
            esc_html__('QR Code', 'rs-qr-code-generator'),
            [$this, 'render_meta_box'],
            'post',
            'side',
            'default'
        );
    }

    public function enqueue_admin_assets(string $hook): void
    {
        if (! in_array($hook, ['post.php', 'post-new.php'], true)) {
            return;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;

        if (! $screen || $screen->post_type !== 'post') {
            return;
        }

        wp_enqueue_style(
            'rs-qr-code-generator-admin',
            RS_QR_CODE_GENERATOR_URL . 'assets/admin.css',
            [],
            RS_QR_CODE_GENERATOR_VERSION
        );

        wp_enqueue_script(
            'rs-qr-code-generator-admin',
            RS_QR_CODE_GENERATOR_URL . 'assets/admin.js',
            ['jquery'],
            RS_QR_CODE_GENERATOR_VERSION,
            true
        );

        wp_localize_script(
            'rs-qr-code-generator-admin',
            'RSQRCodeGenerator',
            [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'action' => self::AJAX_ACTION,
                'nonce' => wp_create_nonce(self::NONCE_ACTION),
                'i18n' => [
                    'generating' => esc_html__('Generating...', 'rs-qr-code-generator'),
                    'generate' => esc_html__('Generate QR Code', 'rs-qr-code-generator'),
                    'regenerate' => esc_html__('Regenerate QR Code', 'rs-qr-code-generator'),
                    'error' => esc_html__('Could not generate QR code. Please try again.', 'rs-qr-code-generator'),
                ],
            ]
        );
    }

    public function render_meta_box(WP_Post $post): void
    {
        $qr = $this->get_qr_data($post->ID);
        $title = get_the_title($post);
        $is_stale = $qr['attachment_id'] && $qr['title_hash'] !== $this->title_hash($title);

        wp_nonce_field(self::NONCE_ACTION, 'rs_qr_code_nonce');
?>
        <div
            class="rs-qr-panel"
            data-post-id="<?php echo esc_attr((string) $post->ID); ?>"
            data-has-code="<?php echo esc_attr($qr['url'] ? '1' : '0'); ?>">
            <div class="rs-qr-status" aria-live="polite"></div>

            <div class="rs-qr-preview-wrap<?php echo esc_attr($qr['url'] ? '' : ' is-empty'); ?>">
                <?php if ($qr['url']) : ?>
                    <img class="rs-qr-preview" src="<?php echo esc_url($qr['url']); ?>" alt="<?php echo esc_attr__('Post title QR code', 'rs-qr-code-generator'); ?>">
                <?php else : ?>
                    <div class="rs-qr-placeholder"><?php echo esc_html__('No QR code generated yet.', 'rs-qr-code-generator'); ?></div>
                <?php endif; ?>
            </div>

            <?php if ($is_stale) : ?>
                <p class="rs-qr-note"><?php echo esc_html__('Post title changed after the last QR code was generated.', 'rs-qr-code-generator'); ?></p>
            <?php endif; ?>

            <div class="rs-qr-actions">
                <button type="button" class="button button-primary rs-qr-generate">
                    <?php echo esc_html($qr['url'] ? __('Regenerate QR Code', 'rs-qr-code-generator') : __('Generate QR Code', 'rs-qr-code-generator')); ?>
                </button>

                <?php if ($qr['url']) : ?>
                    <a class="button rs-qr-download" href="<?php echo esc_url($qr['url']); ?>" download="<?php echo esc_attr($qr['download_name']); ?>">
                        <?php echo esc_html__('Download', 'rs-qr-code-generator'); ?>
                    </a>
                <?php else : ?>
                    <a class="button rs-qr-download is-hidden" href="#" download>
                        <?php echo esc_html__('Download', 'rs-qr-code-generator'); ?>
                    </a>
                <?php endif; ?>
            </div>
        </div>
<?php
    }

    public function ajax_generate_qr_code(): void
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        $post_id = isset($_POST['postId']) ? absint($_POST['postId']) : 0;
        $post = $post_id ? get_post($post_id) : null;

        if (! $post || $post->post_type !== 'post') {
            wp_send_json_error(['message' => esc_html__('Invalid post.', 'rs-qr-code-generator')], 400);
        }

        if (! current_user_can('edit_post', $post_id)) {
            wp_send_json_error(['message' => esc_html__('Permission denied.', 'rs-qr-code-generator')], 403);
        }

        $title = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';

        if ($title === '') {
            $title = sanitize_text_field(wp_unslash($post->post_title));
        }

        if ($title === '') {
            wp_send_json_error(['message' => esc_html__('Please add a post title first.', 'rs-qr-code-generator')], 400);
        }

        $result = $this->generate_qr_code($post_id, $title);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()], 500);
        }

        wp_send_json_success($result);
    }

    private function generate_qr_code(int $post_id, string $title): array|\WP_Error
    {
        if (! class_exists(PngWriter::class)) {
            return new \WP_Error('missing_qr_package', esc_html__('QR package is missing. Please run composer install for this plugin.', 'rs-qr-code-generator'));
        }

        $upload = wp_upload_dir();

        if (! empty($upload['error'])) {
            return new \WP_Error('upload_dir_error', $upload['error']);
        }

        $dir = trailingslashit($upload['basedir']) . 'rs-qr-codes/' . $post_id;
        $url_base = trailingslashit($upload['baseurl']) . 'rs-qr-codes/' . $post_id;

        if (! wp_mkdir_p($dir)) {
            return new \WP_Error('qr_dir_error', esc_html__('Could not create QR code upload directory.', 'rs-qr-code-generator'));
        }

        $can_create_png = extension_loaded('gd') && function_exists('imagecreate');
        $extension = $can_create_png ? 'png' : 'svg';
        $filename = $this->create_qr_filename($title, $extension);
        $path = trailingslashit($dir) . $filename;
        $url = trailingslashit($url_base) . $filename;

        try {
            $qr_code = new QrCode(
                data: $title,
                encoding: new \Endroid\QrCode\Encoding\Encoding('UTF-8'),
                errorCorrectionLevel: ErrorCorrectionLevel::High,
                size: 720,
                margin: 24,
                roundBlockSizeMode: RoundBlockSizeMode::Margin,
                foregroundColor: new Color(19, 19, 19),
                backgroundColor: new Color(255, 255, 255)
            );

            if ($can_create_png) {
                $logo = new Logo(
                    path: RS_QR_CODE_GENERATOR_DIR . 'assets/logo.jpg',
                    resizeToWidth: 260,
                    punchoutBackground: true
                );

                $writer = new PngWriter();
                $writer->write($qr_code, $logo)->saveToFile($path);
            } else {
                $writer = new SvgWriter();
                $svg = $this->add_logo_to_svg($writer->write($qr_code)->getString());

                if (file_put_contents($path, $svg) === false) {
                    throw new \RuntimeException('Could not save SVG QR code.');
                }
            }
        } catch (\Throwable $throwable) {
            return new \WP_Error('qr_generation_error', $throwable->getMessage());
        }

        $this->delete_existing_attachment($post_id);

        $attachment_id = $this->insert_attachment($post_id, $path, $url, $title);

        if (is_wp_error($attachment_id)) {
            return $attachment_id;
        }

        update_post_meta($post_id, self::META_ATTACHMENT_ID, $attachment_id);
        update_post_meta($post_id, self::META_TITLE_HASH, $this->title_hash($title));

        return [
            'attachmentId' => $attachment_id,
            'url' => $url . '?v=' . time(),
            'downloadUrl' => $url,
            'downloadFilename' => $filename,
            'downloadLabel' => esc_html__('Download', 'rs-qr-code-generator'),
            'buttonLabel' => esc_html__('Regenerate QR Code', 'rs-qr-code-generator'),
            'message' => esc_html__('QR code generated.', 'rs-qr-code-generator'),
        ];
    }

    private function insert_attachment(int $post_id, string $path, string $url, string $title): int|\WP_Error
    {
        $filetype = wp_check_filetype($path);
        $mime_type = $filetype['type'] ?: (str_ends_with($path, '.svg') ? 'image/svg+xml' : 'image/png');
        $attachment_id = wp_insert_attachment(
            [
                'guid' => $url,
                'post_mime_type' => $mime_type,
                'post_title' => sprintf('QR Code - %s', $title),
                'post_content' => '',
                'post_status' => 'inherit',
            ],
            $path,
            $post_id
        );

        if (is_wp_error($attachment_id)) {
            return $attachment_id;
        }

        if ($mime_type === 'image/png') {
            require_once ABSPATH . 'wp-admin/includes/image.php';

            $metadata = wp_generate_attachment_metadata($attachment_id, $path);
            wp_update_attachment_metadata($attachment_id, $metadata);
        }

        return (int) $attachment_id;
    }

    private function add_logo_to_svg(string $svg): string
    {
        $logo_path = RS_QR_CODE_GENERATOR_DIR . 'assets/logo.jpg';

        if (! file_exists($logo_path)) {
            return $svg;
        }

        $logo_data = file_get_contents($logo_path);

        if ($logo_data === false) {
            return $svg;
        }

        $logo_width = 260;
        $logo_height = 84;
        $dimensions = function_exists('getimagesize') ? getimagesize($logo_path) : false;

        if (is_array($dimensions) && ! empty($dimensions[0]) && ! empty($dimensions[1])) {
            $logo_height = (int) round($logo_width * ((int) $dimensions[1] / (int) $dimensions[0]));
        }

        $padding = 18;
        $x = (720 - $logo_width) / 2;
        $y = (720 - $logo_height) / 2;
        $background_x = $x - $padding;
        $background_y = $y - $padding;
        $background_width = $logo_width + ($padding * 2);
        $background_height = $logo_height + ($padding * 2);
        $logo = sprintf(
            '<rect x="%s" y="%s" width="%s" height="%s" rx="10" fill="#fff"/><image x="%s" y="%s" width="%s" height="%s" preserveAspectRatio="xMidYMid meet" href="data:image/jpeg;base64,%s"/>',
            esc_attr((string) $background_x),
            esc_attr((string) $background_y),
            esc_attr((string) $background_width),
            esc_attr((string) $background_height),
            esc_attr((string) $x),
            esc_attr((string) $y),
            esc_attr((string) $logo_width),
            esc_attr((string) $logo_height),
            esc_attr(base64_encode($logo_data))
        );

        return str_replace('</svg>', $logo . '</svg>', $svg);
    }

    private function create_qr_filename(string $title, string $extension): string
    {
        $filename = sanitize_file_name($title);

        if ($filename === '') {
            $filename = 'qr-code';
        }

        return $filename . '.' . $extension;
    }

    private function get_qr_data(int $post_id): array
    {
        $attachment_id = absint(get_post_meta($post_id, self::META_ATTACHMENT_ID, true));
        $title_hash = (string) get_post_meta($post_id, self::META_TITLE_HASH, true);
        $url = '';
        $download_name = '';

        if ($attachment_id) {
            $file = get_attached_file($attachment_id);
            $candidate_url = wp_get_attachment_url($attachment_id);

            if ($file && file_exists($file) && $candidate_url) {
                $url = $candidate_url;
                $download_name = basename($file);
            }
        }

        return [
            'attachment_id' => $attachment_id,
            'title_hash' => $title_hash,
            'url' => $url,
            'download_name' => $download_name,
        ];
    }

    private function delete_existing_attachment(int $post_id): void
    {
        $attachment_id = absint(get_post_meta($post_id, self::META_ATTACHMENT_ID, true));

        if ($attachment_id) {
            wp_delete_attachment($attachment_id, true);
        }
    }

    private function title_hash(string $title): string
    {
        return hash('sha256', $title);
    }
}
