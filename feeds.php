<?php
function cleanSummary($value) {
    $text = (string) $value;
    $text = preg_replace('#<(script|style)\\b[^>]*>.*?</\\1>#is', ' ', $text);
    $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\\s+/u', ' ', trim($text));
    if ($text === '') return '';
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_substr($text, 0, 260, 'UTF-8') . (mb_strlen($text, 'UTF-8') > 260 ? '…' : '');
    }
    return strlen($text) > 500 ? substr($text, 0, 500) . '…' : $text;
}
$urls = [];
foreach (file('feeds.txt', FILE_IGNORE_NEW_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || strpos($line, '#') === 0) continue;
    $parts = explode('|', $line, 2);
    $url = trim($parts[0]);
    if (!filter_var($url, FILTER_VALIDATE_URL)) continue;
    $urls[] = ['url' => $url, 'category' => trim($parts[1] ?? '未分类') ?: '未分类'];
}
$postLimit = 5;
$result = '';
$feeda = array();
$feedn = 0;
foreach ($urls as $feedConfig) {
    $url = $feedConfig['url'];
    $sourceCategory = $feedConfig['category'];
echo "\n"."Getting feed from: ". $url." \n";
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/102.0.0.0 Safari/537.36',
        CURLOPT_REFERER => 'https://www.bing.com/',
        CURLOPT_TIMEOUT_MS => 7000,
        CURLOPT_ENCODING => 'gzip',
        CURLOPT_FOLLOWLOCATION => true
    ]);
    
    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if (curl_errno($ch) || $httpcode != 200) {
         echo "\n"."Error loading feed from " . $url . " " . $httpcode . " " . curl_error($ch) ." \n ";
         $feedn++;
        continue;
    }
    curl_close($ch);


    $response = str_ireplace(array("media:thumbnail",'<media:group>','</media:group>'), array("thumbnail",'',''), $response);
    $feed = simplexml_load_string($response);
    if ($feed) {
        $feedType = strtolower($feed->getName());
        $count = 0;
        if ($feedType === 'rss') {
            foreach ($feed->channel->item as $item) {
                if ($count >= $postLimit) {
                    break;
                }
                $description = $item->description;
                $summary = (string) $item->description;
                if ($summary === '') {
                    $contentNamespace = $item->children('content', true);
                    $summary = (string) ($contentNamespace->encoded ?? '');
                }
                $image = null;
               if (isset($item->image) && isset($item->image->url)) {
                    $image = $item->image->url;
                         
                } else if (isset($item->thumbnail) && isset($item->thumbnail->attributes()['url'])) {
                    $image = (string)$item->thumbnail->attributes()['url'];
         
                } else {   
                    preg_match('/<img.+src=[\'"](?P<src>.+?)[\'"].*>/i', $description, $imagematch);
                    if ($imagematch && isset($imagematch['src'])) {
                        $image = $imagematch['src'];
                    } else if (isset($feed->channel->image) && isset($feed->channel->image->url)) {
                        $image = $feed->channel->image->url;
                    }
                      
                }
                                 
                $audiourl = null;
                if (isset($item->enclosure) && isset($item->enclosure['url']) && isset($item->enclosure['type']) && strpos($item->enclosure['type'], 'audio/mpeg') !== false) {
                 $audiourl = $item->enclosure['url'];
                }

                $feeda[$feedn]['link'] = (string) $item->link;
                $feeda[$feedn]['title'] = (string) $item->title;
                if(empty($feeda[$feedn]['title'])) {$feeda[$feedn]['title'] = substr(strip_tags($description),0,140);}
                $feeda[$feedn]['ch'] = (string) $feed->channel->title;
                $feeda[$feedn]['category'] = $sourceCategory;
                $feeda[$feedn]['date'] = strtotime((string) $item->pubDate);
                  
                $feeda[$feedn]['image'] = (string) $image;
                $feeda[$feedn]['summary'] = cleanSummary($summary);
                $feeda[$feedn]['audio'] = (string) $audiourl;
                $count++; $feedn++;
            }
        } elseif ($feedType === 'feed') {
            foreach ($feed->entry as $entry) {
                if ($count >= $postLimit) {
                    break;
                }
                $content = $entry->content;
                $summary = (string) ($entry->summary ?? $entry->content ?? '');
                $image = null;
                if (isset($entry->image) && isset($entry->image->url)) {
                 $image = $entry->image->url;
                } elseif (isset($entry->{'thumbnail'}) && isset($entry->{'thumbnail'}->attributes()['url'])) {
                $image = (string) $entry->{'thumbnail'}->attributes()['url'];
                } else {
                 $content = $entry->content;
                 preg_match('/<img.+src=[\'"](?P<src>.+?)[\'"].*>/i', $content, $imagematch);
                 $image = ($imagematch && isset($imagematch['src'])) ? $imagematch['src'] : (isset($feed->image) && isset($feed->image->url) ? $feed->image->url : null);
                 }
                $audiourl = null;
                if (isset($entry->link) && isset($entry->link['rel']) && $entry->link['rel'] == 'enclosure' && isset($entry->link['href']) && isset($entry->link['type']) && strpos($entry->link['type'], 'audio/mpeg') !== false) {
                    $audiourl = $entry->link['href'];
                }

                $feeda[$feedn]['link'] = (string) $entry->link['href'];
                $feeda[$feedn]['title'] = (string) $entry->title;
                $feeda[$feedn]['ch'] = (string) $feed->title;
                $feeda[$feedn]['category'] = $sourceCategory;
                $feeda[$feedn]['date'] = strtotime((string) ($entry->published ?? $entry->updated));
                $feeda[$feedn]['image'] = (string) $image;
                $feeda[$feedn]['summary'] = cleanSummary($summary);
                $feeda[$feedn]['audio'] = (string) $audiourl;
            

                $count++; $feedn++;
            }
        }
     
     
    } else {
         echo 'Failed to parse feed from ' . $url;
         $feedn++;
    }
}
usort($feeda, fn($a, $b) => $b['date'] <=> $a['date']);
$outhtml = '';
$categoryCounts = [];
foreach ($feeda as $item) {
    $cat = trim((string)($item['category'] ?? '未分类')) ?: '未分类';
    $categoryCounts[$cat] = ($categoryCounts[$cat] ?? 0) + 1;
}
ksort($categoryCounts, SORT_NATURAL | SORT_FLAG_CASE);
$categoryHtml = '';
foreach ($categoryCounts as $cat => $count) {
    $safeCat = htmlspecialchars($cat, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $categoryHtml .= '<button class="category-filter" type="button" data-category="'.$safeCat.'"><span>'.$safeCat.'</span><small>'.$count.'</small></button>';
}
$outoptions = '<option value="All">All Channels</option>';
$outchannels = array();
$index = 0;
foreach($feeda as $post) {
if(!in_array($post['ch'],$outchannels)) {
$outoptions .= '<option value="'.$post['ch'].'">'.$post['ch'].'</option>';
$outchannels[] = $post['ch'];
}
$isaudio = !empty($post['audio']) ? 1 : 0;
$category = htmlspecialchars((string)($post['category'] ?? '未分类'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$outhtml .= '<div class="post" data-channel="'.htmlspecialchars($post['ch'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'" data-category="'.$category.'" data-ts="'.$post['date'].'" data-audio="'.$isaudio.'">';
if(!empty($post['image'])){
$outhtml .= '<div class="leftpan"><img src="'.$post['image'].'" alt="'.$post['title'].'"/ ></div>';
}
else {
  $domain = parse_url($post['link'], PHP_URL_HOST);
  $outhtml .= '<div class="leftpan"><img src="https://s2.googleusercontent.com/s2/favicons?domain='.urlencode($domain).'" alt="'.$post['title'].'"/><span class="domain">'.$domain.'</span></div>';
}
$outhtml .= '<div class="rightpan"><div class="feedname"><span class="channel">'.$post['ch'].'</span> &bull; <span class="date">'.date('M d, Y',$post['date']).'</span></div>
<h2><a href="'.$post['link'].'" target="_blank" rel="noopener noreferrer">'.$post['title'].'</a></h2>';
if (!empty($post['summary'])) {
    $outhtml .= '<p class="summary">'.htmlspecialchars($post['summary'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</p>';
}
if(!empty($post['audio'])){
$cover = !empty($post['image']) ? $post['image'] : '';
$outhtml .= '<div class="audio podcast-player">
<div class="vinyl-disc"><img src="'.htmlspecialchars($cover, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'" alt="" loading="lazy"></div>
<div class="podcast-controls"><span class="podcast-label">PODCAST</span><button type="button" data-aid="'.$index.'" aria-label="播放或暂停播客">▶ 播放</button></div>
<audio src="'.htmlspecialchars($post['audio'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'" preload="metadata" aid="'.$index.'"></audio></div>';
$index++;
}





$outhtml .='
</div></div>
';}
file_put_contents('feed.json', json_encode($feeda));
$template = file_get_contents('base.html');
$html = str_replace(array('<!-- posts here -->','<!-- options here -->','<!-- categories here -->'),array($outhtml,$outoptions,$categoryHtml),$template);
file_put_contents('public/index.html', $html);



?>
