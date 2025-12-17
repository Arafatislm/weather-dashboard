<?php
/**
 * Plugin Name: Weather Dashboard
 * Plugin URI: https://github.com/arafatislm/weather-dashboard
 * Description: Displays weather for specific cities using Open-Meteo API. Use [weather_dashboard].
 * Version: 3.1.0
 * Author: Arafatul Islam
 * Author URI: https://arafatislam.net
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: weather-dashboard
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

class Weather_Dashboard {

    private $cache_key = 'twd_weather_data_v2';
    private $icon_base_url = 'https://cdn.jsdelivr.net/gh/Makin-Things/weather-icons/animated/';

    private $default_cities = [
        'Mission'        => ['lat' => 26.2159, 'lon' => -98.3253],
        'El Paso'        => ['lat' => 31.7619, 'lon' => -106.4850],
        'San Antonio'    => ['lat' => 29.4241, 'lon' => -98.4936],
        'Eagle Pass'     => ['lat' => 28.7091, 'lon' => -100.4995],
        'Austin'         => ['lat' => 30.2672, 'lon' => -97.7431],
        'Corpus Christi' => ['lat' => 27.8006, 'lon' => -97.3964],
        'Presidio'       => ['lat' => 29.5607, 'lon' => -104.3730],
        'Laredo'         => ['lat' => 27.5306, 'lon' => -99.4803],
    ];

    public function __construct() {
        add_shortcode( 'weather_dashboard', [ $this, 'render_shortcode' ] );
        add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
        add_action( 'admin_init', [ $this, 'settings_init' ] );
        add_action( 'wp_head', [ $this, 'add_resource_hints' ] ); 
        add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
    }

    /**
     * Optimization: Preconnect & Preload to speed up asset delivery
     */
    public function add_resource_hints() {
        // Preload commonly used icons to break critical request chains
        $preload_icons = ['clear-day.svg', 'cloudy.svg', 'rainy-2.svg', 'thunderstorms.svg'];
        foreach ($preload_icons as $icon) {
            echo '<link rel="preload" as="image" href="' . esc_url($this->icon_base_url . $icon) . '">' . "\n";
        }
        // Add preconnect
        echo '<link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>' . "\n";
    }

    /**
     * Register REST API Endpoint for AJAX Loading
     */
    public function register_rest_routes() {
        register_rest_route( 'twd/v1', '/data', [
            'methods'  => 'GET',
            'callback' => [ $this, 'handle_rest_request' ],
            'permission_callback' => '__return_true',
        ] );
    }

    public function handle_rest_request() {
        // Fetch fresh data (this handles the cache logic internally)
        $data = $this->fetch_weather_data(true); 
        
        if ( empty( $data ) ) {
            return new WP_Error( 'no_data', 'Weather data unavailable', [ 'status' => 404 ] );
        }

        $html = $this->generate_weather_html( $data );
        return new WP_REST_Response( [ 'html' => $html ] );
    }

    /**
     * Shortcode Handler - Implements Hybrid Loading Strategy
     */
    public function render_shortcode( $atts ) {
        // Check if we have valid cached data
        $cached_data = get_transient( $this->cache_key );
        
        ob_start();
        
        // Output CSS Styles (Critical CSS)
        $this->output_css();

        echo '<div id="twd-weather-container">';
        
        if ( $cached_data !== false && !empty($cached_data) ) {
            // STRATEGY 1: CACHE HIT - Render HTML immediately (Fastest LCP)
            echo $this->generate_weather_html( $cached_data );
        } else {
            // STRATEGY 2: CACHE MISS - Render Skeleton & Fetch via AJAX (Non-blocking)
            echo $this->generate_skeleton_html();
            $this->output_client_script();
        }
        
        echo '</div>';

        return ob_get_clean();
    }

    private function output_client_script() {
        $api_url = esc_url_raw( rest_url( 'twd/v1/data' ) );
        ?>
        <script>
        (function() {
            fetch('<?php echo $api_url; ?>')
                .then(response => response.json())
                .then(data => {
                    if(data.html) {
                        const container = document.getElementById('twd-weather-container');
                        container.style.opacity = '0';
                        setTimeout(() => {
                            container.innerHTML = data.html;
                            container.style.opacity = '1';
                        }, 200); // Smooth transition
                    }
                })
                .catch(err => console.error('Weather load failed', err));
        })();
        </script>
        <?php
    }

    private function generate_skeleton_html() {
        $cities = $this->get_cities_config();
        $icon_size = get_option( 'twd_icon_size', '3.5rem' );
        
        $html = '<div class="twd-horizontal">';
        foreach( $cities as $city => $coords ) {
            $html .= '
            <div class="twd-card twd-skeleton">
                <div class="twd-sk-line twd-sk-city"></div>
                <div class="twd-sk-row">
                    <div class="twd-sk-circle"></div>
                    <div class="twd-sk-line twd-sk-temp"></div>
                </div>
                <div class="twd-sk-line twd-sk-desc"></div>
            </div>';
        }
        $html .= '</div>';
        return $html;
    }

    private function generate_weather_html( $data ) {
        $cities_list = $this->get_cities_config();
        $use_animated = get_option( 'twd_use_animated', '1' );
        
        $html = '<div class="twd-horizontal">';
        
        foreach ( $cities_list as $city => $coords ) {
            $city_data = isset($data[$city]) ? $data[$city] : null;
            if (!$city_data) continue;
            
            $cond = $this->get_weather_condition( $city_data['code'], $use_animated );
            
            $html .= '<div class="twd-card">';
            $html .= '<div class="twd-card-city">' . esc_html($city) . '</div>';
            $html .= '<div class="twd-weather-row">';
            $html .= '<div class="twd-card-temp">' . esc_html($city_data['temp']) . '°</div>';
            $html .= '<div class="twd-card-icon">' . $cond['icon'] . '</div>';
            $html .= '</div>';
            $html .= '<div class="twd-card-desc">' . esc_html($cond['label']) . '</div>';
            $html .= '</div>';
        }
        
        $html .= '</div>';
        return $html;
    }

    private function output_css() {
        $bg_color = get_option( 'twd_bg_color', '#ffffff' );
        $text_color = get_option( 'twd_text_color', '#333333' );
        $font_size = get_option( 'twd_font_size', '16px' );
        $temp_font_size = get_option( 'twd_temp_font_size', '1.6em' );
        $icon_size = get_option( 'twd_icon_size', '3.5rem' );
        $card_gap = get_option( 'twd_card_gap', '12px' );
        $min_card_width = get_option( 'twd_min_card_width', '85px' ); // New Option
        ?>
        <style>
            #twd-weather-container {
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
                margin: 20px 0;
                width: 100%;
                font-size: <?php echo esc_html($font_size); ?>;
                transition: opacity 0.3s ease;
            }
            .twd-horizontal {
                display: flex;
                flex-wrap: wrap;
                justify-content: center;
                gap: <?php echo esc_html($card_gap); ?>;
            }
            .twd-card {
                background: <?php echo esc_html($bg_color); ?>;
                border: 1px solid rgba(0,0,0,0.1);
                border-radius: 10px;
                padding: 2px 2px;
                display: flex;
                flex: 0 0 auto;
                min-width: <?php echo esc_html($min_card_width); ?>;
                flex-direction: column;
                align-items: center;
                justify-content: space-between;
                gap: 0px;
                box-shadow: 0 2px 4px rgba(0,0,0,0.05);
                color: <?php echo esc_html($text_color); ?>;
            }
            .twd-card-city { margin: 0; font-size: 1em; font-weight: 500; line-height: 1.2; text-align: center; }
            .twd-weather-row { display: flex; align-items: center; justify-content: center; gap: 2px; margin: 5px 0; }
            .twd-card-icon { margin: 0; display: flex; align-items: center; justify-content: center; height: <?php echo esc_html($icon_size); ?>; width: <?php echo esc_html($icon_size); ?>; }
            .twd-anim-icon { max-width: 100%; max-height: 100%; object-fit: contain; display: block; margin: 0 auto; }
            .twd-emoji { font-size: <?php echo esc_html($icon_size); ?>; line-height: 1; }
            .twd-card-temp { font-size: <?php echo esc_html($temp_font_size); ?>; font-weight: 800; margin: 0; }
            .twd-card-desc { display: block; font-size: 0.8em; opacity: 0.8; margin-top: 0px; text-align: center; }

            /* Skeleton Loader Styles */
            .twd-skeleton { animation: twd-pulse 1.5s infinite; }
            .twd-sk-line { background: #e0e0e0; border-radius: 4px; }
            .twd-sk-city { height: 1em; width: 60%; margin-bottom: 8px; }
            .twd-sk-row { display: flex; align-items: center; gap: 5px; margin: 5px 0; }
            .twd-sk-circle { width: <?php echo esc_html($icon_size); ?>; height: <?php echo esc_html($icon_size); ?>; background: #e0e0e0; border-radius: 50%; }
            .twd-sk-temp { height: 1.5em; width: 40px; background: #e0e0e0; }
            .twd-sk-desc { height: 0.8em; width: 80%; margin-top: 5px; }
            @keyframes twd-pulse {
                0% { opacity: 1; } 50% { opacity: 0.5; } 100% { opacity: 1; }
            }
        </style>
        <?php
    }

    private function fetch_weather_data( $force_refresh = false ) {
        // If not forcing refresh, try to get from cache first
        if ( ! $force_refresh ) {
            $cached = get_transient( $this->cache_key );
            if ( $cached !== false ) return $cached;
        }

        // Cache Miss or Force Refresh
        $minutes = (int) get_option( 'twd_cache_time', 60 );
        if ($minutes < 1) $minutes = 60;
        $seconds = $minutes * 60;

        $cities = $this->get_cities_config();
        $lats = []; $lons = [];
        foreach ( $cities as $city => $coords ) {
            $lats[] = $coords['lat'];
            $lons[] = $coords['lon'];
        }

        $api_url = add_query_arg([
            'latitude' => implode(',', $lats),
            'longitude' => implode(',', $lons),
            'current_weather' => 'true',
            'temperature_unit' => 'fahrenheit',
            'windspeed_unit' => 'mph'
        ], 'https://api.open-meteo.com/v1/forecast');

        $response = wp_remote_get( $api_url );
        if ( is_wp_error( $response ) ) return [];

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $data ) ) return [];

        $results = isset($data[0]) ? $data : [ $data ];
        $formatted_data = [];
        $city_names = array_keys( $cities );

        foreach ( $results as $index => $loc ) {
            if ( ! isset( $city_names[$index] ) ) continue;
            $formatted_data[$city_names[$index]] = [
                'temp' => round($loc['current_weather']['temperature']),
                'wind' => $loc['current_weather']['windspeed'],
                'code' => $loc['current_weather']['weathercode']
            ];
        }

        set_transient( $this->cache_key, $formatted_data, $seconds );
        return $formatted_data;
    }

    /**
     * Maps WMO weather codes to labels and icons.
     */
    private function get_weather_condition( $code, $use_animated = true ) {
        $map = [
            0  => ['Clear Sky',       '☀️', 'clear-day.svg'],
            1  => ['Mainly Clear',    '🌤️', 'cloudy-1-day.svg'],
            2  => ['Partly Cloudy',   '⛅', 'cloudy-2-day.svg'],
            3  => ['Overcast',        '☁️', 'cloudy.svg'],
            45 => ['Foggy',           '🌫️', 'fog.svg'],
            48 => ['Rime Fog',        '🌫️', 'frost.svg'],
            51 => ['Light Drizzle',   '🌦️', 'rainy-1.svg'],
            53 => ['Drizzle',         '🌦️', 'rainy-1.svg'],
            55 => ['Heavy Drizzle',   '🌧️', 'rainy-2.svg'],
            56 => ['Freezing Drizzle', '🌧️', 'rain-and-sleet-mix.svg'],
            57 => ['Heavy Freezing Drizzle', '🌧️', 'rain-and-sleet-mix.svg'],
            61 => ['Slight Rain',     '🌧️', 'rainy-1.svg'],
            63 => ['Rain',            '🌧️', 'rainy-2.svg'],
            65 => ['Heavy Rain',      '⛈️', 'rainy-3.svg'],
            66 => ['Freezing Rain',    '🌧️', 'rain-and-snow-mix.svg'],
            67 => ['Heavy Freezing Rain', '⛈️', 'rain-and-snow-mix.svg'], 
            71 => ['Slight Snow',     '🌨️', 'snowy-1.svg'],
            73 => ['Snow',            '❄️', 'snowy-2.svg'],
            75 => ['Heavy Snow',      '❄️', 'snowy-3.svg'],
            77 => ['Snow Grains',     '🌨️', 'hail.svg'],
            80 => ['Rain Showers',    '🌦️', 'rainy-1.svg'],
            81 => ['Rain Showers',    '🌦️', 'rainy-2.svg'],
            82 => ['Violent Showers', '⛈️', 'rainy-3.svg'],
            85 => ['Snow Showers',    '🌨️', 'snowy-1.svg'],
            86 => ['Heavy Snow Showers', '❄️', 'snowy-3.svg'],
            95 => ['Thunderstorm',    '⚡', 'thunderstorms.svg'],
            96 => ['Thunderstorm',    '⛈️', 'scattered-thunderstorms.svg'],
            99 => ['Heavy Hail',      '⛈️', 'severe-thunderstorm.svg'],
        ];

        $data = isset($map[$code]) ? $map[$code] : ['Unknown', '🌡️', 'clear-day.svg'];
        
        $label = $data[0];
        $icon  = $use_animated 
            ? '<img src="' . esc_url($this->icon_base_url . $data[2]) . '" alt="' . esc_attr($label) . '" class="twd-anim-icon" width="56" height="56" fetchpriority="high">' 
            : '<span class="twd-emoji">' . $data[1] . '</span>';

        return ['label' => $label, 'icon' => $icon];
    }

    // --- Admin Settings ---
    public function add_admin_menu() {
        add_options_page( 'Weather Dashboard Settings', 'Weather Dashboard', 'manage_options', 'weather_dashboard', [ $this, 'options_page_html' ] );
    }

    public function settings_init() {
        register_setting( 'twd_options', 'twd_cities_list', [ 'sanitize_callback' => 'sanitize_textarea_field' ] );
        register_setting( 'twd_options', 'twd_use_animated', [ 'default' => '1' ] );
        register_setting( 'twd_options', 'twd_bg_color',    [ 'default' => '#ffffff' ] );
        register_setting( 'twd_options', 'twd_text_color',  [ 'default' => '#333333' ] );
        register_setting( 'twd_options', 'twd_font_size',   [ 'default' => '16px' ] );
        register_setting( 'twd_options', 'twd_temp_font_size', [ 'default' => '1.6em' ] );
        register_setting( 'twd_options', 'twd_icon_size',   [ 'default' => '3.5rem' ] );
        register_setting( 'twd_options', 'twd_card_gap',    [ 'default' => '12px' ] );
        register_setting( 'twd_options', 'twd_cache_time',  [ 'default' => '60' ] );
        // NEW Setting
        register_setting( 'twd_options', 'twd_min_card_width', [ 'default' => '85px' ] );

        add_settings_section( 'twd_section_data', 'Data Settings', null, 'weather_dashboard' );
        add_settings_section( 'twd_section_cities', 'City Management', null, 'weather_dashboard' );
        add_settings_section( 'twd_section_style', 'Visual Styles', null, 'weather_dashboard' );

        add_settings_field( 'twd_cache_time', 'Update Frequency (Minutes)', [ $this, 'text_field_cb' ], 'weather_dashboard', 'twd_section_data', ['label_for' => 'twd_cache_time', 'desc' => 'How often to fetch new data (e.g., 5, 10, or 60). Lower = more "real-time" but slightly slower page loads.'] );
        add_settings_field( 'twd_cities_list', 'Cities List', [ $this, 'cities_field_cb' ], 'weather_dashboard', 'twd_section_cities' );
        add_settings_field( 'twd_use_animated', 'Icon Type', [ $this, 'checkbox_field_cb' ], 'weather_dashboard', 'twd_section_style', ['label_for' => 'twd_use_animated', 'desc' => 'Use Animated SVGs'] );
        add_settings_field( 'twd_bg_color', 'Card Background', [ $this, 'color_field_cb' ], 'weather_dashboard', 'twd_section_style', ['label_for' => 'twd_bg_color'] );
        add_settings_field( 'twd_text_color', 'Text Color', [ $this, 'color_field_cb' ], 'weather_dashboard', 'twd_section_style', ['label_for' => 'twd_text_color'] );
        add_settings_field( 'twd_font_size', 'Base Font Size', [ $this, 'text_field_cb' ], 'weather_dashboard', 'twd_section_style', ['label_for' => 'twd_font_size', 'desc' => 'e.g., 16px'] );
        add_settings_field( 'twd_temp_font_size', 'Temperature Size', [ $this, 'text_field_cb' ], 'weather_dashboard', 'twd_section_style', ['label_for' => 'twd_temp_font_size', 'desc' => 'e.g., 1.6em or 32px'] );
        add_settings_field( 'twd_icon_size', 'Icon Size', [ $this, 'text_field_cb' ], 'weather_dashboard', 'twd_section_style', ['label_for' => 'twd_icon_size', 'desc' => 'e.g., 3.5rem or 60px'] );
        add_settings_field( 'twd_card_gap', 'Card Gap', [ $this, 'text_field_cb' ], 'weather_dashboard', 'twd_section_style', ['label_for' => 'twd_card_gap', 'desc' => 'e.g., 12px'] );
        // NEW Field
        add_settings_field( 'twd_min_card_width', 'Minimum Card Width', [ $this, 'text_field_cb' ], 'weather_dashboard', 'twd_section_style', ['label_for' => 'twd_min_card_width', 'desc' => 'e.g., 85px or 120px'] );
    }

    public function cities_field_cb() {
        $value = get_option( 'twd_cities_list' );
        if ( empty( $value ) ) {
            $lines = [];
            foreach ( $this->default_cities as $name => $coords ) {
                $lines[] = "$name|{$coords['lat']}|{$coords['lon']}";
            }
            $value = implode( "\n", $lines );
        }
        echo '<textarea name="twd_cities_list" rows="10" cols="50" style="font-family:monospace;">' . esc_textarea( $value ) . '</textarea>';
        echo '<p class="description">Format: <code>City Name|Lat|Lon</code></p>';
    }

    public function checkbox_field_cb( $args ) {
        $id = $args['label_for'];
        $val = get_option( $id, '1' );
        $checked = checked( $val, '1', false );
        echo '<input type="checkbox" name="' . esc_attr( $id ) . '" value="1" ' . $checked . '> ' . esc_html( $args['desc'] );
    }

    public function color_field_cb( $args ) {
        $id = $args['label_for'];
        $val = get_option( $id );
        echo '<input type="color" name="' . esc_attr( $id ) . '" value="' . esc_attr( $val ) . '">';
    }

    public function text_field_cb( $args ) {
        $id = $args['label_for'];
        $val = get_option( $id );
        $desc = isset($args['desc']) ? $args['desc'] : '';
        echo '<input type="text" name="' . esc_attr( $id ) . '" value="' . esc_attr( $val ) . '">';
        if($desc) echo '<p class="description">' . esc_html($desc) . '</p>';
    }

    public function options_page_html() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        ?>
        <div class="wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
            <form action="options.php" method="post">
                <?php
                settings_fields( 'twd_options' );
                do_settings_sections( 'weather_dashboard' );
                submit_button( 'Save Settings' );
                ?>
            </form>
        </div>
        <?php
    }

    private function get_cities_config() {
        $raw = get_option( 'twd_cities_list' );
        if ( empty( $raw ) ) return $this->default_cities;
        $cities = [];
        $lines = explode( "\n", $raw );
        foreach ( $lines as $line ) {
            $parts = explode( '|', $line );
            if ( count( $parts ) >= 3 ) {
                $cities[trim($parts[0])] = [ 'lat' => floatval(trim($parts[1])), 'lon' => floatval(trim($parts[2])) ];
            }
        }
        return !empty($cities) ? $cities : $this->default_cities;
    }
}

new Weather_Dashboard();
