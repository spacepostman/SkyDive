<?php

/////___  _  ____   ______ _____     _______ 
//// ___|| |/ /\ \ / /  _ \_ _\ \   / / ____|
///\___ \| ' /  \ V /| | | | | \ \ / /|  _|  
/// ___) | . \   | | | |_| | |  \ V / | |___ 
///|____/|_|\_\  |_| |____/___|  \_/  |_____|

//// SKYDIVE - PHP based AutoPoster for the BlueSky Social Network
/// (C) Copyright Spacepostman - January 2025 ///////////////////
/// Support: https://github.com/spacepostman?tab=repositories


//// Configure your custom credentials for Bluesky below

$handle = 'ladslist.bsky.social'; 
/// This should be your BlueSky handle
$password = 'n5y3-y47h-expo-ooe4'; 
/// You can generate an app-specifc password here: https://bsky.app/settings/privacy-and-security

$text = "Custom text for the caption in the post.\n\n
Links, Linebreaks and #Hastags will automatically be converted. \n\n
#SkyDive by Spacepostman https://github.com/spacepostman?tab=repositories";
/// Custom text for the caption in the post. links, Linebreaks (as \n ) and #Hastags are detected and encoded

$thumb1 = 'uploads/face.jpg';
$imagePath = $thumb1;
/// Local path or URL to desired image on server $thumb
/// Must be JPG OR PNG. (Gif uploads not yet supported via the At API)

$altText = "Alt text: " . $text . " ";
/// Accessible alt image descripton goes here, replace "Alt text"
$postText = $text;

////// YOU DO NOT NEED TO CONFIGURE BELOW THIS LINE (IF YOU USE BYSKY.SOCIAL)

echo "<head><title>SkyDive - auto bluesky poster</title>
<link rel=\"stylesheet\" href=\"https://ladslist.co.uk/css/ladslist_desktop.css\" type=\"text/css\">
<body bgcolor=\"#000000\" leftmargin=\"0\" topmargin=\"0\" marginwidth=\"0\" marginheight=\"0\">
<div id=\"navbars_red\" style=\" max-width:50%;\"><div class=\"cordcontent\" style=\" max-width:100%; background:#000000; border-color:#000000;\">      
<p style=\"font-size:24px; color:#ffffff; font-weight:bold; font-family:Arial;\"> Skydive BlueSky AutoPoster</B><br>
<span style=\"font-size:18px; color:#cc0000; font-weight:normal; font-family:Verdana;\">";


/// Connecting function to BlueSky AT API
function bluesky_connect($handle, $password) {
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => 'https://bsky.social/xrpc/com.atproto.server.createSession',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => json_encode(['identifier' => $handle, 'password' => $password]),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json'
        ],
    ]);

    $response = curl_exec($curl);
    curl_close($curl);

    $session = json_decode($response, true);
    if (!$session || isset($session['error'])) {
        die("Failed to connect: " . ($session['error'] ?? 'Unknown error'));
    }
    return $session;
}

/// Bluesky image upload within 1 MB Limit.
function upload_media_to_bluesky($session, $imagePath) {
    $imageData = file_get_contents($imagePath);
    
    /// Check image size is correct
    if (strlen($imageData) > 1000000) { /// 1 MB limit
        throw new Exception("Image too large. Maximum size is 1MB.");
    }

    $mimeType = mime_content_type($imagePath);
    if (!$mimeType || !in_array($mimeType, ['image/jpeg', 'image/png'])) {
        throw new Exception("Unsupported image format. Use JPEG or PNG.");
    }

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => 'https://bsky.social/xrpc/com.atproto.repo.uploadBlob',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $imageData,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $session['accessJwt'],
            'Content-Type: ' . $mimeType
        ],
    ]);

    $response = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($httpCode != 200) {
        throw new Exception("Image upload failed with HTTP status: " . $httpCode);
    }

    $uploadedImage = json_decode($response, true);
    if (isset($uploadedImage['error'])) {
        throw new Exception("Image upload failed: " . $uploadedImage['error']);
    }
    return $uploadedImage['blob'];
}

/// Detect URLS within text 
function detect_url($text) {
    $regex = '/\b(?:(?:https?|ftp):\/\/|www\.)[-A-Z0-9+&@#\/%?=~_|$!:,.;]*[A-Z0-9+&@#\/%=~_|$]/i';
    preg_match($regex, $text, $matches);
    return $matches[0] ?? null;
}

/// Identify and encode #Hashtags within text
function detect_hashtags($text) {
    preg_match_all('/#(\w+)/', $text, $matches);
    return $matches[1] ?? []; // 
}

/// Main function to create post with image, hyperlink, and #hashtags
function create_post_with_image_link_and_hashtags($session, $text, $imageBlob, $altText) {
    $postData = [
        'repo' => $session['did'],
        'collection' => 'app.bsky.feed.post',
        'record' => [
            '$type' => 'app.bsky.feed.post',
            'text' => $text,
            'createdAt' => date('c'),
            'embed' => [
                '$type' => 'app.bsky.embed.images',
                'images' => [
                    [
                        'image' => $imageBlob,
                        'alt' => $altText
                    ]
                ]
            ]
        ]
    ];

    $facets = [];

    /// Detect and encode URLs within text
    if ($url = detect_url($text)) {
        $start = strpos($text, $url);
        $end = $start + strlen($url);
        $facets[] = [
            'index' => [
                'byteStart' => $start,
                'byteEnd' => $end
            ],
            'features' => [
                [
                    '$type' => 'app.bsky.richtext.facet#link',
                    'uri' => $url
                ]
            ]
        ];
    }

    /// Detect and encode #hashtags
    $hashtags = detect_hashtags($text);
    foreach ($hashtags as $hashtag) {
        $hashtagText = '#' . $hashtag;
        $start = strpos($text, $hashtagText);
        $end = $start + strlen($hashtagText);
        $facets[] = [
            'index' => [
                'byteStart' => $start,
                'byteEnd' => $end
            ],
            'features' => [
                [
                    '$type' => 'app.bsky.richtext.facet#tag',
                    'tag' => $hashtag
                ]
            ]
        ];
    }

    if (!empty($facets)) {
        $postData['record']['facets'] = $facets;
    }

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => 'https://bsky.social/xrpc/com.atproto.repo.createRecord',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($postData),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $session['accessJwt'],
            'Content-Type: application/json'
        ],
    ]);

    $response = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($httpCode != 200) {
        throw new Exception("Post creation failed with HTTP status: " . $httpCode);
    }

    $postResult = json_decode($response, true);
    if ($postResult === null && $response !== '') {
        throw new Exception("Failed to decode JSON response: " . $response);
    }

    if (isset($postResult['error'])) {
        throw new Exception("Post creation failed: " . $postResult['error']);
    }

    return $postResult;
}

try {
    $session = bluesky_connect($handle, $password);
    $imageBlob = upload_media_to_bluesky($session, $imagePath);
    $postTextWithLinkAndTags = $postText; 
    $postResult = create_post_with_image_link_and_hashtags($session, $postTextWithLinkAndTags, $imageBlob, $altText);
    
    /// Output response
    if ($postResult === null) {
        echo "Post created successfully, but response was null. Raw response: " . var_export($response, true);
    } else {
        echo "Post created successfully: " . json_encode($postResult, JSON_PRETTY_PRINT);
    }
} catch (Exception $e) {
    echo "An error occurred: " . $e->getMessage();
}
$theyear = date("Y");
echo "</span></p></div><div class=\"cordcontent\" style=\" max-width:100%; background:#ffffff; border-color:#000000;\"><p style=\"font-size:24px; color:#000000; font-weight:bold; font-family:Arial;\">Content posted:</p>
<p style=\"font-size:16px; color:#000000; font-weight:normal; font-family:verdana;\">$postTextWithLinkAndTags<br><br><img src=\"$thumb1\" style=\"magin:4px;border:2px;\"></p>
</div>    <div class=\"cordcontent\" style=\" max-width:100%; background:#cc0000; border-color:#000000;\">
<p style=\"font-size:24px; color:#000000; font-weight:bold; font-family:Arial;\"> Skydive BlueSky AutoPoster</B><br>
<span style=\"font-size:18px; color:#cc0000; font-weight:normal; font-family:Verdana;\"></div><span style=\"font-size:16px; color:#ffcc00; font-weight:bold; font-family:Verdana;\">
 Support: #SkyDive by Spacepostman <a href=\"https://github.com/spacepostman?tab=repositories\">
 https://github.com/spacepostman?tab=repositories</a><br></span>
 <span style=\"font-size:14px; color:#cccccc; font-weight:normal; font-family:Verdana;\">
 &copy Copyright $theyear BoydBoss - boydskintrade@gmail.com</div>";
?>
