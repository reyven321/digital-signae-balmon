<?php
/**
 * RSS Feed Manager Class
 * Handles fetching and parsing RSS feeds
 */

class RSSFeedManager {
    private $conn;
    
    public function __construct() {
        require_once 'enhanced_config.php';
        $this->conn = getConnection();
    }
    
    /**
     * Fetch single RSS feed by ID
     */
    public function fetchFeed($feedId) {
        // Get feed details
        $stmt = $this->conn->prepare("SELECT * FROM rss_feeds WHERE id = ? AND is_active = 1");
        $stmt->bind_param("i", $feedId);
        $stmt->execute();
        $feed = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if (!$feed) {
            return ['success' => false, 'error' => 'Feed not found or inactive'];
        }
        
        return $this->parseFeed($feed);
    }
    
    /**
     * Refresh all active feeds
     */
    public function refreshAllFeeds() {
        $result = $this->conn->query("SELECT * FROM rss_feeds WHERE is_active = 1");
        $feeds = $result->fetch_all(MYSQLI_ASSOC);
        
        $totalNewItems = 0;
        $successfulFeeds = 0;
        
        foreach ($feeds as $feed) {
            $parseResult = $this->parseFeed($feed);
            if ($parseResult['success']) {
                $totalNewItems += $parseResult['new_items'];
                $successfulFeeds++;
            }
        }
        
        return [
            'success' => true,
            'total_feeds' => count($feeds),
            'successful_feeds' => $successfulFeeds,
            'total_new_items' => $totalNewItems
        ];
    }
    
    /**
     * Parse RSS feed and save items
     */
    private function parseFeed($feed) {
        try {
            // Fetch RSS content
            $context = stream_context_create([
                'http' => [
                    'timeout' => 10,
                    'user_agent' => 'Digital Signage BMFR/1.0'
                ]
            ]);
            
            $xmlContent = @file_get_contents($feed['url'], false, $context);
            
            if ($xmlContent === false) {
                return ['success' => false, 'error' => 'Failed to fetch feed'];
            }
            
            // Disable libxml errors
            libxml_use_internal_errors(true);
            
            // Parse XML
            $xml = simplexml_load_string($xmlContent);
            
            if ($xml === false) {
                $errors = libxml_get_errors();
                libxml_clear_errors();
                return ['success' => false, 'error' => 'Failed to parse XML'];
            }
            
            $newItems = 0;
            
            // Determine RSS version (RSS 2.0 or Atom)
            if (isset($xml->channel->item)) {
                // RSS 2.0
                $items = $xml->channel->item;
                
                foreach ($items as $item) {
                    $title = (string)$item->title;
                    $description = (string)$item->description;
                    $link = (string)$item->link;
                    $pubDate = (string)$item->pubDate;
                    $guid = (string)($item->guid ?? $link);
                    
                    // Convert pubDate to MySQL datetime
                    $pubDateTime = null;
                    if ($pubDate) {
                        $timestamp = strtotime($pubDate);
                        if ($timestamp !== false) {
                            $pubDateTime = date('Y-m-d H:i:s', $timestamp);
                        }
                    }
                    
                    // Check if item already exists
                    $checkStmt = $this->conn->prepare("SELECT id FROM rss_items WHERE guid = ?");
                    $checkStmt->bind_param("s", $guid);
                    $checkStmt->execute();
                    $exists = $checkStmt->get_result()->num_rows > 0;
                    $checkStmt->close();
                    
                    if (!$exists) {
                        // Insert new item
                        $insertStmt = $this->conn->prepare(
                            "INSERT INTO rss_items (feed_id, title, description, link, pub_date, guid) VALUES (?, ?, ?, ?, ?, ?)"
                        );
                        $insertStmt->bind_param("isssss", $feed['id'], $title, $description, $link, $pubDateTime, $guid);
                        $insertStmt->execute();
                        $insertStmt->close();
                        $newItems++;
                    }
                }
            } 
            elseif (isset($xml->entry)) {
                // Atom feed
                $items = $xml->entry;
                
                foreach ($items as $item) {
                    $title = (string)$item->title;
                    $description = (string)($item->summary ?? $item->content);
                    $link = (string)$item->link['href'];
                    $pubDate = (string)($item->updated ?? $item->published);
                    $guid = (string)$item->id;
                    
                    // Convert pubDate to MySQL datetime
                    $pubDateTime = null;
                    if ($pubDate) {
                        $timestamp = strtotime($pubDate);
                        if ($timestamp !== false) {
                            $pubDateTime = date('Y-m-d H:i:s', $timestamp);
                        }
                    }
                    
                    // Check if item already exists
                    $checkStmt = $this->conn->prepare("SELECT id FROM rss_items WHERE guid = ?");
                    $checkStmt->bind_param("s", $guid);
                    $checkStmt->execute();
                    $exists = $checkStmt->get_result()->num_rows > 0;
                    $checkStmt->close();
                    
                    if (!$exists) {
                        // Insert new item
                        $insertStmt = $this->conn->prepare(
                            "INSERT INTO rss_items (feed_id, title, description, link, pub_date, guid) VALUES (?, ?, ?, ?, ?, ?)"
                        );
                        $insertStmt->bind_param("isssss", $feed['id'], $title, $description, $link, $pubDateTime, $guid);
                        $insertStmt->execute();
                        $insertStmt->close();
                        $newItems++;
                    }
                }
            }
            
            // Update last_fetch
            $updateStmt = $this->conn->prepare("UPDATE rss_feeds SET last_fetch = NOW() WHERE id = ?");
            $updateStmt->bind_param("i", $feed['id']);
            $updateStmt->execute();
            $updateStmt->close();
            
            return [
                'success' => true,
                'new_items' => $newItems,
                'feed_name' => $feed['name']
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get latest items for display
     */
    public function getLatestItems($limit = 10, $feedId = null) {
        if ($feedId) {
            $stmt = $this->conn->prepare(
                "SELECT * FROM rss_items WHERE feed_id = ? ORDER BY pub_date DESC LIMIT ?"
            );
            $stmt->bind_param("ii", $feedId, $limit);
        } else {
            $stmt = $this->conn->prepare(
                "SELECT ri.*, rf.name as feed_name 
                 FROM rss_items ri 
                 JOIN rss_feeds rf ON ri.feed_id = rf.id 
                 WHERE rf.is_active = 1 
                 ORDER BY ri.pub_date DESC 
                 LIMIT ?"
            );
            $stmt->bind_param("i", $limit);
        }
        
        $stmt->execute();
        $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        return $items;
    }
    
    /**
     * Clean old items (older than X days)
     */
    public function cleanOldItems($days = 30) {
        $cutoffDate = date('Y-m-d', strtotime("-$days days"));
        
        $stmt = $this->conn->prepare("DELETE FROM rss_items WHERE pub_date < ?");
        $stmt->bind_param("s", $cutoffDate);
        $stmt->execute();
        $deleted = $stmt->affected_rows;
        $stmt->close();
        
        return $deleted;
    }
    
    public function __destruct() {
        if ($this->conn) {
            $this->conn->close();
        }
    }
}

// Auto-refresh script (untuk CRON job)
if (php_sapi_name() === 'cli' || (isset($argv[0]) && basename($argv[0]) === 'RSSFeedManager.php')) {
    echo "Running RSS auto-refresh...\n";
    
    $manager = new RSSFeedManager();
    $result = $manager->refreshAllFeeds();
    
    echo "Completed!\n";
    echo "Total feeds: {$result['total_feeds']}\n";
    echo "Successful: {$result['successful_feeds']}\n";
    echo "New items: {$result['total_new_items']}\n";
    
    // Clean old items
    $deleted = $manager->cleanOldItems(30);
    echo "Cleaned $deleted old items\n";
}