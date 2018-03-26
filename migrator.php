<?php
    require_once 'wp-config.php';
    require_once 'wp-content/plugins/amazon-s3-and-cloudfront/wordpress-s3.php';
    global $table_prefix;
    /*backup*/
    $dbHost = $wpdb->dbhost;
    $dbName = $wpdb->dbname;
    $dbPassword = $wpdb->dbpassword;
    $dbSaveFileLocation = dirname(__FILE__) . '/backup-' . date('Y-m-d-h:i:s', time()) . '.sql';
    $dbUser = $wpdb->dbuser;

    exec("mysqldump --user=$dbUser --password=$dbPassword --host=$dbHost $dbName > $dbSaveFileLocation");

    /*getting offload S3 Values*/
    amazon_web_services_require_files();
    $aws = new Amazon_Web_Services( __FILE__ );
    $awsS3 = new Amazon_S3_And_CloudFront(__FILE__, $aws);
    $bucket = ( !empty( $awsS3->get_setting('cloudfront') ) ) ? $awsS3->get_setting('cloudfront') : $awsS3->get_setting('bucket'); // If you're using a CDN and the setting is saved, use that for CloudFront URL replcaement, otherwise use the S3 bucket name
    $region = $awsS3->get_setting('region');
    $folderPrefix = $awsS3->get_setting('object-prefix');

    /*checking if requesting to remove the existing media updates*/
    if( isset($_GET['remove']) && $_GET['remove'] ) { // ?removeS3Update=true
        if( $dbConnection = mysqli_connect($dbHost, $dbUser, $dbPassword, $dbName) ) {

            $removeAmazonS3Info = "DELETE FROM " . $table_prefix . "postmeta WHERE meta_key = 'amazonS3_info';";
            $reversePostContentHref = updatePostContent(
                                            'href',
                                            $table_prefix . 'posts',
                                            get_site_url( $wpdb->blogid ) . '/wp-content/' . $folderPrefix,
                                            "https://cdn.tbsplanet.com/$folderPrefix",
                                            true
                                        );
            $reversePostContentSrc = updatePostContent(
                                            'src',
                                            $table_prefix . 'posts',
                                            get_site_url( $wpdb->blogid ) . '/wp-content/' . $folderPrefix,
                                            "https://cdn.tbsplanet.com/$folderPrefix",
                                            true
                                        );

            echo 'RUNNING COMMAND: ' . $removeAmazonS3Info . ' - '; 
            if( $dbConnection->query($removeAmazonS3Info) ) {
                echo ' <strong>TRUE, ' . $dbConnection->affected_rows . ' rows affected</strong><br />';
            }

            echo 'RUNNING COMMAND: ' . $reversePostContentHref . ' - ';
            if( $dbConnection->query($reversePostContentHref) ) {
                echo ' <strong>TRUE, ' . $dbConnection->affected_rows . ' rows affected</strong><br />';
            }

            echo 'RUNNING COMMAND: ' . $reversePostContentSrc . ' - '; 
            if( $dbConnection->query($reversePostContentSrc) ) {
                echo ' <strong>TRUE, ' . $dbConnection->affected_rows . ' rows affected</strong><br />';
            }   

        }

        echo '<h3>removed records for WP Offload S3 - reverted back to use local server</h3>';

        exit(); //Don't run remaining and exit php
    }

    /*start from scratch by deleting offload S3 plugin entry*/
    $wpdb->delete($table_prefix . 'postmeta',
        array(
            'meta_key'  => 'amazonS3_info'
        )
    );

    /*getting attachments with meta*/
    $picturesToUpdate = $wpdb->get_results("SELECT * FROM " . $table_prefix . "postmeta WHERE meta_key = '_wp_attached_file'");

    foreach($picturesToUpdate as $picture) {
        $pictureMetaData = serialize(array(
            'bucket'    => $bucket,
            'key'       => $folderPrefix . $picture->meta_value,
            'region'    => $region
                    ));

        /*inserting record that WP Offload S3 looks for changing the image URL to S3 URL*/
        $wpdb->insert($table_prefix . 'postmeta', 
            array(
                'post_id'   => $picture->post_id,
                'meta_key'  => 'amazonS3_info',
                'meta_value'  => $pictureMetaData
            )
        );

    }

    if( $dbConnection = mysqli_connect($dbHost, $dbUser, $dbPassword, $dbName) ) {

        $hrefMySQLUpdate = updatePostContent(
                                'href',
                                            $table_prefix . 'posts',
                                            get_site_url( $wpdb->blogid ) . '/wp-content/' . $folderPrefix,
                                            "https://cdn.tbsplanet.com/$folderPrefix",
                                            true
                            );
        $srcMySQLUpdate = updatePostContent(
                                'src',
                                            $table_prefix . 'posts',
                                            get_site_url( $wpdb->blogid ) . '/wp-content/' . $folderPrefix,
                                            "https://cdn.tbsplanet.com/$folderPrefix",
                                            true
                            );

        echo 'RUNNING COMMAND: ' . $hrefMySQLUpdate . ' - '; 
        if( $dbConnection->query($hrefMySQLUpdate) ) {
            echo ' <strong>TRUE, ' . $dbConnection->affected_rows . ' rows affected</strong><br />';
        }

        echo 'RUNNING COMMAND: ' . $srcMySQLUpdate . ' - ';
        if( $dbConnection->query($srcMySQLUpdate) ) {
            echo ' <strong>TRUE, ' . $dbConnection->affected_rows . ' rows affected</strong><br />';
        }

    }

    echo '<h3>All set!!! Enjoy now</h3>';



    function updatePostContent($type, $table, $blog, $s3bucket, $reverse = FALSE) {
        // $reverse is to remove the post_content updates and put them back to serving locally
        $from   = ( !$reverse ) ? $blog : $s3bucket;
        $to     = ( !$reverse ) ? $s3bucket : $blog;

        return "UPDATE $table SET post_content = replace(post_content, '$type=\"$from', '$type=\"$to');";
    }

?>
