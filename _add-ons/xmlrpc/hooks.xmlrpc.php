<?php
require_once 'vendor/autoload.php';

use Symfony\Component\Filesystem\Filesystem\Exception\IOException as IOException;

// Utility functions
function is_set($arr, $field) {
  return (isset($arr[$field]) && (count($arr[$field]) > 0));
}

// used to hold an StatamicEntry
class StatamicEntry
{
    const PUBLISH = 'publish';
    const DRAFT = 'draft';
    const HIDDEN = 'hidden';
    
    public $title = '';
    public $categories = null;
    public $tags = null;
    public $content = '';
    public $post_status = StatamicEntry::PUBLISH;
    public $link = null;
    public $author = null;
}

class Hooks_xmlrpc extends Hooks
{
    private $link_custom_field;
    private $author_custom_field;
    
    private $current_user;
    
    // https://github.com/danielberlinger/rsd
    public function xmlrpc__rsd() {

        $siteURL = Config::getSiteURL();
        $blog = $this->fetchConfig('blog', 'blog');
        
        $api_str = 'blogID="'.$blog.'" preferred="true" apiLink="'.$siteURL.'/TRIGGER/xmlrpc/api" />';

		$rsd = '<?xml version="1.0" encoding="UTF-8"?>';
		$rsd .= '<rsd version="1.0" xmlns="http://archipelago.phrasewise.com/rsd">';
		$rsd .= '<service>';
		$rsd .= '<engineName>Statamic XML-RPC</engineName>';
		$rsd .= '<engineLink>https://github.com/edalzell/statamic-xmlrpc/</engineLink>';
		$rsd .= '<homePageLink>'.$siteURL.'</homePageLink>';
		$rsd .= '<apis>';
		$rsd .= '<api name="Movable Type" '.$api_str;
		$rsd .= '<api name="MovableType" '.$api_str;
		$rsd .= '<api name="MetaWeblog" '.$api_str;
		$rsd .= '<api name="Blogger" '.$api_str;
		$rsd .= '</apis>';
		$rsd .= '</service>';
		$rsd .= '</rsd>';
		
		echo $rsd;
    }

    public function xmlrpc__api()
    {
        $this->link_custom_field = $this->fetchConfig('link_custom_field', 'titlelink');
        $this->author_custom_field = $this->fetchConfig('author_custom_field', 'author');

        $this->server = new IXR_Server(array(
            'metaWeblog.getPost' => array($this, 'getPost'),
            'metaWeblog.newPost'  => array($this, 'newPost'),
            'metaWeblog.editPost' => array($this, 'editPost'),
            'metaWeblog.deletePost' => array($this, 'deletePost'),
            'metaWeblog.getCategories' => array($this, 'getCategories'),
            'metaWeblog.getRecentPosts' => array($this, 'getRecentPosts'),
            'metaWeblog.getUsersBlogs' => array($this, 'getUsersBlogs' ),
            'metaWeblog.newMediaObject' => array($this, 'newMediaObject' ),

            // Blogger
            'blogger.getUsersBlogs' => array($this, 'getUsersBlogs'),
            'blogger.deletePost' => array($this, 'deletePost'),

            // MoveableType
            'mt.supportedTextFilters' => array($this, 'supportedTextFilters'),
            'mt.getPostCategories' => array($this, 'getPostCategories'),
            'mt.setPostCategories' => array($this, 'setPostCategories'),
            'mt.getCategoryList' => array($this, 'getCategories'),
            'mt.publishPost' => array($this, 'publishPost'),
    
            // Wordpress
            'wp.getUsersBlogs' => array($this, 'getUsersBlogs'),
        ));
    }
    
    private function makeFilename($folder, $title, $status) {
        // create the file path
        $page_path = Path::resolve($folder);
        
        // create the appropriate prefix
        $entry_type = Statamic::get_entry_type($page_path);

        $order_prefix = "";
        if ($entry_type == 'date') {
            if (Config::get('_entry_timestamps')) {
                $order_prefix = date('Y-m-d-Hi-');
            }
            else {
                $order_prefix = date('Y-m-d-');
            }
        } else if ($entry_type == 'number') {
            $order_prefix = Statamic::get_next_numeric($page_path) . "-";
        }

        // the slug comes from the title in lowercase with '-' as a delimiter
        $slug = Slug::make($title, array('lowercase' => true));

        // get the status prefix from the post status
        $status_prefix = Slug::getStatusPrefix($status);

        // make the file name
        return $status_prefix . $order_prefix . $slug . '.' . Config::getContentType();
    }
    
    private function makeFullPath($folder, $title, $status) {
        return Path::assemble(BASE_PATH,
                              Config::getContentRoot(), 
                              Path::resolve($folder),
                              makeFilename($folder, $title, $status));
    }
    
    // from a page and an StatamicEntry slug, create the full path
    private function getFullPath($folder, $slug) {
        return Path::assemble(BASE_PATH,
                              Config::getContentRoot(), 
                              Path::resolve($folder . DIRECTORY_SEPARATOR . $slug) . '.' . Config::getContentType());
    }

    private function authenticate($username, $password) {
        
        // attempt to load the Member object
        $user = Auth::getMember($username);
        
        // if no Member object, or checkPassword fails, return an error
        if (!$user || !$user->checkPassword($password) || ($user->hasRole('admin') === false)) {
            $error = new IXR_Error(403, "Incorrect username or password");
            
            // halt immediately stop and send back a response
            $app = \Slim\Slim::getInstance();
            $app->halt(200, $error->getXml());
        }
        
        $this->current_user = $username;
    }
    
    private function makePostId($blog, $slug) {
        return $blog . '#' . $slug;
    }
    
    private function parsePostId($postid) {
        return explode('#', $postid);
    }
    
    /* Statamic only has 3 statuses: publish, draft, hidden, but we might receive
       anyone of these statuses: http://codex.wordpress.org/Post_Status_Transitions
       so we have to map appropriately:
       
       new, publish, inherit, trash -> publish
       pending, draft, auto-draft, future -> draft
       private -> hidden
       
       HOWEVER, if the publish flag is false it must be a draft, regardless of the
       post status.
    */
    private function convertPostStatusToEntryStatus($post_status, $publish) {
        $status = '';
        
        $draft = array('pending', 'draft', 'auto-draft', 'future');
        $hidden = array('private');
        
        if (!$publish || in_array($post_status, $draft)) {
            $status = StatamicEntry::DRAFT;
        } else if (in_array($post_status, $hidden)) {
            $status = StatamicEntry::HIDDEN;
        } else {
            $status = StatamicEntry::PUBLISH;
        }
        
        return $status;
    }
    
    private function convertEntryStatusToPostStatus($entry) {
        $status = StatamicEntry::PUBLISH;
        
        if (isset($entry['_is_draft']) && ($entry['_is_draft'] == 1)) {
            $status = StatamicEntry::DRAFT;
        } else if (isset($entry['_is_hidden']) && ($entry['_is_hidden'] == 1)) {
            $status = StatamicEntry::HIDDEN;
        }
    
        return $status;
    }
    
    private function statusesAreEqual($entry, $content) {
        $isHidden = $content['_is_hidden'];
        $isDraft = $content['_is_draft'];
        $isLive = !$isHidden && !$isDraft;
        
        return (($isDraft && ($entry->post_status === StatamicEntry::DRAFT)) ||
                ($isHidden && ($entry->post_status === StatamicEntry::HIDDEN)) ||
                ($isLive && ($entry->post_status === StatamicEntry::PUBLISH)));
    }
    
    // create the structure required for the MetaWeblog API
    private function convertEntryToPost($entry) {
        
        // if no author, use the currently authenticated user
        if (!isset($entry['author']) || empty($entry['author'])) {
            $entry['author'] = $this->current_user;
        } 
        
        $post = array(
            'postid' => $this->makePostId($entry['_folder'], $entry['slug']),
            'title' =>  $entry['title'],
            'description' => $entry['content_raw'],
            'link' => $entry['url'],
            'permaLink' => $entry['permalink'],
            'categories' => isset($entry['categories']) ? $entry['categories'] : null,
            'dateCreated' => date(DateTime::ISO8601, $entry['datestamp']),
            'post_status' => $this->convertEntryStatusToPostStatus($entry),
            'custom_fields' => array(
                array(
                    'key' => $this->link_custom_field,
                    'value' => isset($entry['link']) ? $entry['link'] : null ),
                array(
                    'key' => $this->author_custom_field,
                    'value' => $entry['author'] ),
                ),  
        );

        return $post;
    }
    
    // convert from XMLRPC to Statamic entry
    private function convertPostToEntry($post, $publish=true) {
        $entry = new StatamicEntry();

        // get the StatamicEntry data
        $entry->title = $post['title'];
        
        if (isset($post['description'])) {
            $entry->content = $post['description'];
        }
        
        if (is_set($post, 'categories')) {
            $entry->categories = $post['categories'];
        }
        
        if (is_set($post, 'tags')) {
            $entry->tags = $post['tags'];
        }
        
        $entry->post_status = $this->convertPostStatusToEntryStatus($post['post_status'], $publish);
        
        if (is_set($post, 'custom_fields')) {
            foreach ($post['custom_fields'] as $field) {
                if ($field['key'] === $this->link_custom_field) {
                    $entry->link = $field['value'];
                    break;
                }
                if ($field['key'] === $this->author_custom_field) {
                    $entry->author = $field['value'] ?: $this->getUsername();
                    break;
                }
            }
        // if there are no custom fields, we still need to set the author
        } else {
            $entry->author = $this->current_user;
        }
        return $entry;
    }
    
    // convert from content to an entry we can write to a file
    private function convertContentToEntry($content) {
        $entry = new StatamicEntry();

        // get the StatamicEntry data
        $entry->title = $content['title'];
        
        if (isset($content['content_raw'])) {
            $entry->content = $content['content_raw'];
        }
        
        if (is_set($content, 'categories')) {
            $entry->categories = $content['categories'];
        }
        
        if (is_set($content, 'tags')) {
            $entry->tags = $content['tags'];
        }
        
        if (isset($content['link'])) {
            $entry->link = $content['link'];
        }

        if (isset($content['author'])) {
            $entry->author = $content['author'];
        // if there are no custom fields, we still need to set the author
        } else {
            $entry->author = $this->current_user;
        }
        return $entry;
    }

    function changeStatus($page, $slug, $status) {
        $originalFilePath = $this->getFullPath($page, $slug);

        $pathParts = explode('/', $originalFilePath);
        
        // remove the old prefix then append the new one.
        
        //strip the prefix
        $filename = preg_replace('/_+/', '', end($pathParts));
        
        $status_prefix = Slug::getStatusPrefix($status);
       
        $pathParts[count($pathParts) - 1] = $status_prefix . $filename;

        File::move($originalFilePath, implode('/', $pathParts));
    }
    
    private function saveEntryToFile($entry, $path) {
    
        // Front matter
        $data = array(
          'title' => $entry->title,
          'author' => $entry->author,
        );
        
        // work around this issue: http://lodge.statamic.com/support/698-statamic-thinks-empty-taxonomy-is-not-em
        if (isset($entry->categories) && (count($entry->categories) > 0)) {
            $data['categories'] = $entry->categories;
        }

        // work around this issue: http://lodge.statamic.com/support/698-statamic-thinks-empty-taxonomy-is-not-em
        if (isset($entry->tags) && (count($entry->tags) > 0)) {
            $data['tags'] = $entry->tags;
        }

        if (isset($entry->link) && !empty($entry->link)) {
            $data['link'] = $entry->link;
        }
        
        File::put($path, File::buildContent($data, $entry->content));
        
//        $this->addon->api('relative_cache_buster')->regenerateCache('blog');
    }
    
    // http://codex.wordpress.org/XML-RPC_MetaWeblog_API#metaWeblog.getRecentPosts
    function getRecentPosts($params) {
        $posts = array();
        
        list($blog, $username, $password, $limit) = $params;
 
        $this->authenticate( $username, $password );
                
        // grab the recent posts
        $content_set = ContentService::getContentByFolders($blog);

        // have to filter out everything that's NOT an StatamicEntry
        $content_set->filter(array( 'type' => 'entries' ));

        // sort entries newest to oldest
        $content_set->sort();
        
        // restrict to passed in limit.
        $content_set->limit($limit);
        
        //get content
        $content_array = $content_set->get(true, false);

        // loop through each StatamicEntry and convert to a post
        foreach ($content_array as $entry) {
            $posts[] = $this->convertEntryToPost($entry);   
        }
                
        return $posts;
    }
    
    // http://codex.wordpress.org/XML-RPC_MetaWeblog_API#metaWeblog.getPost
    function getPost($params) {
        list($postid, $username, $password) = $params;

        $this->authenticate($username, $password);
        
        // parse out the page & the StatamicEntry id
        list($page, $slug) = $this->parsePostId($postid);
        
        //convert StatamicEntry to MetaWeblog post
        return $this->convertEntryToPost(Content::get(URL::assemble($page, $slug)));
    }
    
    
    // http://codex.wordpress.org/XML-RPC_MetaWeblog_API#metaWeblog.newPost
    function newPost($params) {
        // pull data out of POST params
        // page is folder to save to
        list($page, $username, $password, $post, $publish) = $params;
        
        $this->authenticate($username, $password);
        
        // create the file path
        $page_path = Path::resolve($page);
        
        // create the appropriate prefix
        $entry_type = Statamic::get_entry_type($page_path);

        $order_prefix = "";
        if ($entry_type == 'date') {
            if (Config::get('_entry_timestamps')) {
                $order_prefix = date('Y-m-d-Hi-');
            }
            else {
                $order_prefix = date('Y-m-d-');
            }
        } else if ($entry_type == 'number') {
            $order_prefix = Statamic::get_next_numeric($page_path) . "-";
        }

        // get the Entry data
        $entry = $this->convertPostToEntry($post, $publish);

        // the slug comes from the title in lowercase with '-' as a delimiter
        $slug = Slug::make($entry->title, array('lowercase' => true));

        // get the status prefix from the post status
        $status_prefix = Slug::getStatusPrefix($entry->post_status);

        // make the file name
        $filename = $status_prefix . $order_prefix . $slug;

        $fullpath = $this->getFullPath($page_path, $filename);
        
        $this->saveEntryToFile($entry, $fullpath);
        
        // this should be unique so clients can use this as the key.
        return $this->makePostId($page, $slug);
    }

    // http://codex.wordpress.org/XML-RPC_MetaWeblog_API#metaWeblog.editPost
    function editPost($params) {
        // parse the POST params
        list($postid, $username, $password, $post, $publish) = $params;

        $this->authenticate($username, $password);
        
        list($page, $slug) = $this->parsePostId($postid);

        /*
            To properly set the categories in Moveable Type from MarsEdit:
                1) metaWeblog.editPost - no categories are passed & publish is false
                2) mt.setPostCategories - to set the categories 
                3) metaWeblog.editPost - no categories are passed & publish is true

            Reasoning:
                The 2nd editPost was (is? in some cases?) because the way Movable Type
                worked it would not republish a blog as static files unless the "publish" 
                flag was true, and you couldn't set categories until after a post was 
                added. So the workaround was to send a post, set the categories, and then 
                change the "publish" flag to true (with another editPost call). 

            So, what we nee do to is check to see if we are doing MT by checking to see
            if the categories are blank. If so, then when we receive the 'true' publish
            parameter, retain the categories that are in the file already
        */

        $content = Content::get(URL::assemble($page, $slug));

        // only get the categories if they are NOT sent AND the publish param is true
        // AND there are categories to set
        if (!is_set($post, 'categories') && is_set($content, 'categories') && $publish) {
            // add them to the struct so they will be written to the file
            $post['categories'] = $content['categories'];
        }
        
        // convert to a Statamic Entry
        $entry = $this->convertPostToEntry($post, $publish);

        $this->saveEntryToFile($entry, $content['_file']);
     
        if (!$this->statusesAreEqual($entry, $content)) {
            $this->changeStatus($page, $slug, $entry->post_status);
        }
    
        return true;
    }
    
    // http://codex.wordpress.org/XML-RPC_MovableType_API#mt.publishPost
    function publishPost($params) {
        list($postid, $username, $password) = $params;

        $this->authenticate($username, $password);

        list($page, $slug) = $this->parsePostId($postid);

        $this->changeStatus($page, $slug, StatamicEntry::PUBLISH);

        return true;
    }
    
    // http://codex.wordpress.org/XML-RPC_MetaWeblog_API#metaWeblog.deletePost
    function deletePost($params) {
        list($ignored, $postid, $username, $password) = $params;

        $this->authenticate($username, $password);
        
        // grab the page & the StatamicEntry id
        list($page, $slug) = $this->parsePostId($postid);
        
        $path = $this->getFullPath($page, $slug);
        
        try {
            File::delete($path);
        } catch (IOException $ioe)
        {
            $error = new IXR_Error(403, $ioe->message);
            
            // halt immediately stop and send back a response
            $app = \Slim\Slim::getInstance();
            $app->halt(200, $error->getXml());
        }
        
        return true;
    }

    // http://codex.wordpress.org/XML-RPC_MetaWeblog_API#metaWeblog.getCategories
    function getCategories($params) {
        list($blog, $username, $password) = $params;

        $this->authenticate($username, $password);

        $taxonomy = ContentService::getTaxonomiesByType('categories');
        $taxonomy->sort();
        
        $taxonomies = $taxonomy->get();

        $categories = array();
        foreach ($taxonomies as $taxonomy) {
            $categories[] = array('categoryId' => $taxonomy['name'], 'categoryName' => $taxonomy['name']);
        }
        
        return $categories;
    }
    
    function getPostCategories($params) {
        list($postid, $username, $password) = $params;

        $this->authenticate($username, $password);
        
        // grab the page & the entry id
        list($page, $slug) = $this->parsePostId($postid);
        
        $content = Content::get(URL::assemble($page, $slug));

        $categories = array();
        if (is_set($content, 'categories')) {
            foreach ($content['categories'] as $category) {
                $categories[] = array(
                    'categoryId' => $category,
                    'categoryName' => $category,
                    'isPrimary' => false,
                    );
            }
        }
        
        return $categories;
    }
    
    function setPostCategories($params) {
        list($postid, $username, $password, $struct) = $params;

        $this->authenticate($username, $password);
        
        list($page, $slug) = $this->parsePostId($postid);

        // get the content
        $content = Content::get(URL::assemble($page, $slug));
        
        $entry = $this->convertContentToEntry($content, true, false);

        $entry->categories = array();
        foreach ($struct as $cat_details) {
            $entry->categories[] = $cat_details['categoryId'];
        }
        
        $this->saveEntryToFile($entry, $content['_file']);

        return true;
    }

    // http://codex.wordpress.org/XML-RPC_MetaWeblog_API#metaWeblog.getUsersBlogs and
    // https://codex.wordpress.org/XML-RPC_Blogger_API#blogger.getUsersBlogs
    function getUsersBlogs($params) {
        list($ignored, $username, $password) = $params;
        $this->authenticate($username, $password);
        
        // get only the top level folders
        $folders = ContentService::getContentTree('/', 1);
        
        /*
        struct
            string blogid
            string blogName
            string url
            string xmlrpc: XML-RPC endpoint for the blog.
            bool isAdmin
        */
        $siteURL = Config::getSiteURL();
        $blogs = array();
        
        foreach ($folders as $folder) {
            $item = array(
                'blogid' => strtolower($folder['title']),
                'blogName' => $folder['title'],
                'url' => $siteURL.$folder['url'],
                'xmlrpc' => $siteURL.'/TRIGGER/xmlrpc/api',
                'isAdmin' => true
            );
            $blogs[] = $item;
        }
        
        return $blogs;
    }
    
    // https://codex.wordpress.org/XML-RPC_MetaWeblog_API#metaWeblog.newMediaObject
    function newMediaObject($params) {
        list($notused, $username, $password, $data) = $params;

        $this->authenticate($username, $password);
                
        // if there's no image data, it's because the client thinks it's already uploaded.
        // Just return the URL
        if (isset($data['bits'])) {
            $fullpath = Path::assemble(BASE_PATH, 'assets', 'img', $data['name']);
        
            // no need to decode
            file_put_contents($fullpath, $data['bits']);
        }
        
        $imgdata['file'] = $data['name'];
        $imgdata['url'] = URL::assemble('assets', 'img', $data['name']);
        $imgdata['type'] = $data['type'];
        
        return($imgdata);
    }
    
    function supportedTextFilters($params) {
        return array();
    }
}