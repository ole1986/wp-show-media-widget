<?php
/*
Plugin Name: Show Media Widget (PDF support)
Description: List media files in a widget filtered by categories
Version:     1.0.2
Author:      ole1986
Author URI:  https://profiles.wordpress.org/ole1986
License:     GPL2
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: mediawidget
 */

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

class Ole1986_MediaWidget extends WP_Widget
{
    private $maxItems = 5;
    private $openInTab = 1;
    private $hideTitle = false;

    /**
     * constructor overload of the WP_Widget class to initialize the media widget
     */
    public function __construct()
    {
        parent::__construct('mediawidget', __('Media Widget', 'mediawidget') . ' (PDF support)', ['description' => __('Show media in widget filtered by category', 'mediawidget')]);

        add_action('wp_ajax_mediawidget_loadmore', [$this, 'loadmore']);
        add_action('wp_ajax_nopriv_mediawidget_loadmore', [$this, 'loadmore']);
        add_action('wp_head', [$this, 'head']);
    }

    /**
     * Used to get media 'posts' based on a category.
     * The category is being created through the 'Enhanced Media Library' plugin
     * 
     * @param int $category the category id to filter for
     * @param int $offset number of items to skip
     * @param int $take number of items to take
     * @return array list of posts with post_type 'attachment'
     */
    private function getMedia($category, $offset = 0, $take = 5)
    {
        $a = ['showposts' => $take, 'offset' => $offset, 'post_type' => 'attachment', 'tax_query' => [['taxonomy' => 'media_category', 'terms' => $category, 'field' => 'ID']]];
        return get_posts($a);
	}
    
    /**
     * Used to receive the count of all media 'posts' stored in a category
     * 
     * @param int $category the category id to filter for
     * @return int number of posts with post_type 'attachment' (filtered by category)
     */
	private function getMediaCount($category) {
		$a = ['fields' => 'ids','post_status' => 'inherit','post_type' => 'attachment', 'tax_query' => [['taxonomy' => 'media_category', 'terms' => $category, 'field' => 'ID']]];
		$query = new WP_Query( $a );
		return $query->found_posts;
    }
    
    /**
     * [AJAX] instant load additional attachment posts from frontend
     */
    public function loadmore()
    {
        $media = $this->getMedia($_POST['category'], $_POST['offset'], $_POST['maxitems']);
        $this->showMedia($media);

        wp_die();
    }

    /**
     * Generate a thumbnail if the media is a pdf document
     * 
     * @param object $m media post object
     * @return string the url of the thumbnail
     */
    protected function generateThumbnailFromPDF($m)
    {
		// skip if no imagick is available
        if (!extension_loaded('gmagick')) {
            echo "No gmagick found";
            return;
        }

        $filepath = get_attached_file($m->ID);
        $destPath = preg_replace("/\.pdf$/i", '-image.png', $filepath);

        $url = wp_get_attachment_url($m->ID);
        $thumbnailUrl = preg_replace("/\.pdf$/i", '-image.png', $url);

        if (file_exists($destPath)) {
            return $thumbnailUrl;
        }

        $imagick = new Gmagick($filepath . '[0]');
        $imagick->thumbnailImage(200, null);
        $imagick->setImageFormat('png');

        $success = $imagick->writeImage($destPath);

		// make it visible in media center
        $attachment_id = wp_insert_attachment(['post_title' => $m->post_title . ' (thumbnail)', 'post_mime_type' => 'image/png'], $destPath, $m->ID);
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $metadata = wp_generate_attachment_metadata($attachment_id, $destPath);
        wp_update_attachment_metadata($attachment_id, $metadata);

        return $thumbnailUrl;
    }

    /**
     * Display the media posts onto the frontend
     * 
     * @param array list of 'attachment' posts
     */
    public function showMedia($media)
    {
        foreach ($media as $m) {
            $previewImg = '';
            if ($m->post_mime_type == 'application/pdf') {
                $thumbnail = $this->generateThumbnailFromPDF($m);
                $previewImg = '<img src="' . $thumbnail . '" />';
            } else {
				$thumbnail = wp_get_attachment_image_url($m->ID);
				$previewImg = '<img src="' . $thumbnail . '" />';
            }
            
            $blank = ($this->openInTab) ? 'target="_blank"' : '';
            $title = (!$this->hideTitle) ? $m->post_title : '';

            $result = '<div class="media-widget-post media-widget-post-default">';
            $result.= '<a href="' . wp_get_attachment_url($m->ID) .'"' . $blank . '>' . $previewImg . '</a>';
            $result.= '<div>'. $title .'</div>';
            $result.= '</div>';

            echo $result;
        }
    }

    /**
     * Display the widget onto the frontend
     */
    public function widget($args, $instance)
    {
        global $post;

        $this->maxItems = (empty($instance['maxitems'])) ? $this->maxItems : intval($instance['maxitems']);
        $this->openInTab = (!empty($instance['newwindow'])) ? 1 : 0;
        $this->hideTitle = (!empty($instance['hidetitle'])) ? true : false;

		// before and after widget arguments are defined by themes
        echo $args['before_widget'];
        echo $args['before_title'] . $instance['title'] . $args['after_title'];

        echo '<div id="mediawidget-' . $instance['category'] . '">';
        $media = $this->getMedia($instance['category'], 0, $this->maxItems);

        $this->showMedia($media);

		echo '</div>';
		
		$count = $this->getMediaCount($instance['category']);
		
		if($count > $this->maxItems) {
			echo '<div style="margin-top: 1em;text-align: center; font-size: small;"><a href="javascript:void(0)" class="mediawidget-readmore" data-category="' . $instance['category'] . '" data-offset="' . $this->maxItems . '" data-maxitems="' . $this->maxItems . '">' . __("Show More", 'mediawidget') . '</a></div>';
		}

        echo $args['after_widget'];
    }

    /**
     * Show the widget form in admin area containing the following input arguments
     * 
     * - Title
     * - Category
     * - Number of items to show
     */
    public function form($instance)
    {
        $title = isset($instance['title']) ? esc_attr($instance['title']) : "";
        $this->maxItems = isset($instance['maxitems']) ? intval($instance['maxitems']) : $this->maxItems;
        $this->openInTab = (!empty($instance['newwindow'])) ? 1 : 0;
        $this->hideTitle = (!empty($instance['hidetitle'])) ? true : false;

        ?>
		<p>
			<label for="<?php echo $this->get_field_id('title'); ?>"><?php _e('Title:', 'mediawidget');?></label>
			<input class="widefat" id="<?php echo $this->get_field_id('title'); ?>" name="<?php echo $this->get_field_name('title'); ?>" type="text" value="<?php echo $title ?>" />
		</p>
		<?php if(is_plugin_active('enhanced-media-library/enhanced-media-library.php')): ?>
        	<?php $cats = get_categories(['taxonomy' => 'media_category']); ?>
			<p>
			<label for="<?php echo $this->get_field_id('category'); ?>"><?php _e('Category:', 'mediawidget');?></label>
			<select class="widefat" id="<?php echo $this->get_field_id('category'); ?>" name="<?php echo $this->get_field_name('category'); ?>">
			<option value="">(None)</option>
			<?php foreach ($cats as $c) {?>
				<option value="<?php echo $c->cat_ID ?>" <?php selected($instance['category'], $c->cat_ID);?>><?php echo $c->name ?></option>
			<?php }?>
			</select>
		<?php else: ?>
			<p>The <a href="/wp-admin/plugin-install.php?s=enhanced+media+library&tab=search&type=term">enhanced media library</a> is required to select categories</p>
		<?php endif; ?>
		</p>
		<p>
            <label for="<?php echo $this->get_field_id('maxitems'); ?>"><?php _e('Max items to show:', 'mediawidget');?></label>
            <input class="widefat" id="<?php echo $this->get_field_id('maxitems'); ?>" name="<?php echo $this->get_field_name('maxitems'); ?>" type="number" value="<?php echo $this->maxItems ?>" />
		</p>
		<p>
            <label for="<?php echo $this->get_field_id('newwindow'); ?>" style="display: inline-block; width: 160px"><?php _e('Open in new tab:', 'mediawidget');?></label>
            <input class="widefat" id="<?php echo $this->get_field_id('newwindow'); ?>" name="<?php echo $this->get_field_name('newwindow'); ?>" type="checkbox" value="1" <?php echo ($this->openInTab) ? "checked" : "" ?> />
		</p>
        <p>
            <label for="<?php echo $this->get_field_id('hidetitle'); ?>" style="display: inline-block; width: 160px"><?php _e('Hide media title:', 'mediawidget');?></label>
            <input class="widefat" id="<?php echo $this->get_field_id('hidetitle'); ?>" name="<?php echo $this->get_field_name('hidetitle'); ?>" type="checkbox" value="1" <?php echo ($this->hideTitle) ? "checked" : "" ?> />
		</p>
		<?php
    }

    public function update($new, $old)
    {
        return $new;
    }

    /**
     * inject javascript and stylesheets into the frontend using 'wp_head' action
     */
    public function head()
    {
        ?>
        <script>
	    jQuery(function(){
    	    var $ = jQuery;

            $('.mediawidget-readmore').click(function(){
                var category = $(this).data('category');
                var offset = parseInt($(this).data('offset'));
                var maxitems = parseInt($(this).data('maxitems'));

                $(this).data('offset', (offset + maxitems));


                $.post('<?php echo admin_url('admin-ajax.php'); ?>', { action: 'mediawidget_loadmore', category: category, offset: offset, maxitems: maxitems }).done(function(data){
                jQuery('#mediawidget-' + category).append(data);
                });

            });
	    });
        </script>
        <style>
            .media-widget-post-default > a > img {
                margin-left: auto;
                margin-right: auto;
            }
            .media-widget-post-default > div {
                font-size: smaller;
                text-align: center;
                margin-bottom: 1em;
            }
        </style>
        <?php
    }

    /**
     * initialize the widget class and text-domain part
     */
    public static function load()
    {
        // load the text domain
        load_plugin_textdomain('mediawidget', false, dirname(plugin_basename(__FILE__)) . '/lang/');
        
        // register self as a widget
        register_widget(get_class());
    }
    
    public static function plugin_action($links){
        if(!is_plugin_active('enhanced-media-library/enhanced-media-library.php'))
        {
            $links =  array_merge($links, ['<a href="' . admin_url( 'plugin-install.php?s=enhanced-media-library&tab=search&type=term' ) . '" style="color: #a00;">Install Enhanced Media Library</a>']);
        }
        return $links;
    }
}

add_action('widgets_init', ['Ole1986_MediaWidget', 'load']);
add_filter( 'plugin_action_links_' . plugin_basename(__FILE__), ['Ole1986_MediaWidget', 'plugin_action'] );
?>
