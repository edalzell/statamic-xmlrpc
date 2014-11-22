<?php
require_once 'vendor/autoload.php';

// Utility functions
function is_set($arr, $field) {
  return (isset($arr[$field]) && (count($arr[$field]) > 0));
}

// used to hold an entry
class Entry
{
    public $title = '';
    public $categories = '';
    public $tags = '';
    public $content = '';
    public $post_status = 'publish';
    public $link = null;
    public $author = null;
}

class Hooks_xmlrpc extends Hooks
{
    private $link_custom_field;
    private $current_user;
    
    // https://github.com/danielberlinger/rsd
    public function xmlrpc__rsd() {

        $siteURL = Config::getSiteURL();
        $blog = $this->fetchConfig('blog', 'blog');

        echo <<<RSD
<?xml version="1.0" encoding="UTF-8"?>
<rsd version="1.0" xmlns="http://archipelago.phrasewise.com/rsd">
<service>
<engineName>Statamic XML-RPC</engineName>
<engineLink>https://github.com/edalzell/statamic-xmlrpc/</engineLink>
<homePageLink>$siteURL</homePageLink>
<apis>
<api name="Movable Type" blogID="$blog" preferred="true" apiLink="$siteURL/TRIGGER/xmlrpc/api" />
<api name="MovableType" blogID="$blog" preferred="false" apiLink="$siteURL/TRIGGER/xmlrpc/api" />
<api name="MetaWeblog" blogID="$blog" preferred="false" apiLink="$siteURL/TRIGGER/xmlrpc/api" />
<api name="Blogger" blogID="$blog" preferred="false" apiLink="$siteURL/TRIGGER/xmlrpc/api" />
</apis>
</service>
</rsd>
RSD;

    }

    public function xmlrpc__api()
    {
        global $link_custom_field, $author_custom_field;
        
        $link_custom_field = $this->fetchConfig('link_custom_field', 'titlelink');
        $author_custom_field = $this->fetchConfig('author_custom_field', 'author');

        try {
            // register the callback and process the POST request
            $this->server = new IXR_Server(array(
                'metaWeblog.getPost' => array($this, 'getPost'),
                'metaWeblog.newPost'  => array($this, 'newPost'),
                'metaWeblog.editPost' => array($this, 'editPost'),
                'metaWeblog.deletePost' => array($this, 'deletePost'),
                'metaWeblog.getCategories' => array($this, 'getCategories'),
                'metaWeblog.getRecentPosts' => array($this, 'getRecentPosts'),
                'metaWeblog.getUsersBlogs' => array($this, 'getUsersBlogs' ),

                // Blogger
                'blogger.deletePost' => array($this, 'deletePost'),

                // MoveableType
                'mt.supportedTextFilters' => array($this, 'supportedTextFilters'),
                'mt.getPostCategories' => array($this, 'getPostCategories'),
                'mt.setPostCategories' => array($this, 'setPostCategories'),
                'mt.getCategoryList' => array($this, 'getCategories'),
        
                // Wordpress
                'wp.getUsersBlogs' => array($this, 'getUsersBlogs'),
            ));
        } catch (AuthorizationException $e) {
            return new IXR_Error(403, "Incorrect username or password");
        }
    }
    
    private function authenticate($username, $password) {
        global $current_user;
        
        // attempt to load the Member object
        $user = Auth::getMember($username);
        
        // if no Member object, or checkPassword fails, return an error
        if (!$user || !$user->checkPassword($password)) {
            $error = new IXR_Error(403, "Incorrect username or password");
            
            // halt immediately stop and send back a response
            $app = \Slim\Slim::getInstance();
            $app->halt(200, $error->getXml());
        }
        
        $current_user = $username;
    }
    
    private function getUsername() {
        global $current_user;
        
        return ($current_user);
    }
    
    // from a page and an entry slug, create the full path
    private function getFullPath($folder, $slug) {
        return Path::assemble(BASE_PATH, Config::getContentRoot(), $folder, $slug . '.' . Config::getContentType());
    }

    private function makePostId($blog, $slug) {
        return $blog . '#' . $slug;
    }
    
    private function parsePostId($postid) {
        return explode('#', $postid);
    }
    
    private function getEntryStatus($entry) {
        $status = 'publish';
        
        if (isset($entry['_is_draft']) && ($entry['_is_draft'] == 1)) {
            $status = 'draft';
        }
    
        return $status;
    }
    
    // create the structure required for the MetaWeblog API
    private function convertEntryToPost($entry) {
        global $link_custom_field, $author_custom_field;
        
        if (!isset($entry['author']) || empty($entry['author'])) {
            $entry['author'] = $this->getUsername();
        } 
        
        $post = array(
            'postid' => $this->makePostId($entry['_folder'], $entry['slug']),
            'title' =>  $entry['title'],
            'description' => $entry['content_raw'],
            'link' => $entry['url'],
            'permaLink' => $entry['permalink'],
            'categories' => isset($entry['categories']) ? $entry['categories'] : null,
            'dateCreated' => date(DateTime::ISO8601, $entry['datestamp']),
            'post_status' => $this->getEntryStatus($entry),
            'custom_fields' => array(
                array(
                    'key' => $link_custom_field,
                    'value' => isset($entry['link']) ? $entry['link'] : null ),
                array(
                    'key' => $author_custom_field,
                    'value' => $entry['author'] ),
                ),  
        );

        return $post;
    }
    
    private function convertPostToEntry($post) {
        global $link_custom_field, $author_custom_field;
        
        $entry = new Entry();

        // get the entry data
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
        
        if (isset($post['post_status'])) {
            $entry->post_status = $post['post_status'];
        }
        
        if (is_set($post, 'custom_fields')) {
            foreach ($post['custom_fields'] as $field) {
                if ($field['key'] === $link_custom_field) {
                    $entry->link = $field['value'];
                    break;
                }
                if ($field['key'] === $author_custom_field) {
                    $entry->author = $field['value'] ?: $this->getUsername();
                    break;
                }
            }
        // if there are no custom fields, we still need to set the author
        } else {
            $entry->author = $this->getUsername();
        }
        return $entry;
    }
    
    private function convertContentToEntry($content) {
        global $link_custom_field, $author_custom_field;
        
        $entry = new Entry();

        // get the entry data
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
            $entry->author = $this->getUsername();
        }
        return $entry;
    }

    private function saveEntryToFile($entry, $path) {
    
        // Front matter
        $data = array(
          'title' => $entry->title,
          'categories' => $entry->categories,
          'tags' => $entry->tags,
          'author' => $entry->author,
        );
        
        if (isset($entry->link) && !empty($entry->link)) {
            $data['link'] = $entry->link;
        }

        // write the file
        File::put($path, File::buildContent($data, $entry->content));
    }
    
    // see http://codex.wordpress.org/XML-RPC_MetaWeblog_API#metaWeblog.getRecentPosts
    function getRecentPosts($params) {
        $posts = array();
        
        list($blog, $username, $password, $limit) = $params;
 
        $this->authenticate( $username, $password );
                
        // grab the recent posts
        $content_set = ContentService::getContentByFolders($blog);

        // have to filter out everything that's NOT an entry
        $content_set->filter(array( 'type' => 'entries' ));

        // sort entries newest to oldest
        $content_set->sort();
        
        // restrict to passed in limit.
        $content_set->limit($limit);
        
        //get content
        $content_array = $content_set->get(true, false);

        // loop through each entry and convert to a post
        foreach ($content_array as $entry) {
            $posts[] = $this->convertEntryToPost($entry);   
        }
                
        return $posts;
    }
    
    // see spec here: http://codex.wordpress.org/XML-RPC_MetaWeblog_API#metaWeblog.getPost
    function getPost($params) {
        list($postid, $username, $password) = $params;

        $this->authenticate($username, $password);
        
        // parse out the page & the entry id
        list($page, $slug) = $this->parsePostId($postid);
        
        //convert entry to MetaWeblog post
        return $this->convertEntryToPost(Content::get(URL::assemble($page, $slug)));
    }
    
    
    // see http://codex.wordpress.org/XML-RPC_MetaWeblog_API#metaWeblog.newPost
    function newPost($params) {
        // pull data out of POST params
        // page is folder to save to
        list($page, $username, $password, $post) = $params;
        
        $this->authenticate($username, $password);
        
        // create the file path
        $page_path = Path::resolve($page);
        
        // create the appropriate prefix
        $entry_type = Statamic::get_entry_type($page_path);

        $prefix = "";
        if ($entry_type == 'date') {
            if (Config::get('_entry_timestamps')) {
                $prefix = date('Y-m-d-Hi-');
            }
            else {
                $prefix = date('Y-m-d-');
            }
        } else if ($entry_type == 'number') {
            $prefix = Statamic::get_next_numeric($page_path) . "-";
        }

        // get the entry data
        $entry = $this->convertPostToEntry($post);

        // the slug comes from the title in lowercase with '-' as a delimiter
        $slug = Slug::make($entry->title, array('lowercase' => true));

        // make the file name
        $filename = $prefix . $slug;

        $fullpath = $this->getFullPath($page_path, $filename);
        
        $this->saveEntryToFile($entry, $fullpath);
        
        // this should be unique so clients can use this as the key.
        return $this->makePostId($page, $slug);
    }

    // see - http://codex.wordpress.org/XML-RPC_MetaWeblog_API#metaWeblog.editPost
    function editPost($params) {
        // parse the POST params
        list($postid, $username, $password, $post, $publish) = $params;

        $this->authenticate($username, $password);
        
        list($page, $slug) = $this->parsePostId($postid);

        /*
            To properly set the categories in Moveable Type:
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
            parameter, retain the categories that are in the file alread
        */

        $content = Content::get(URL::assemble($page, $slug));

        // only get the categories if they are NOT sent AND the publish param is true
        if (!is_set($post, 'categories') && $publish) {
            // add them to the struct so they will be written to the file
            $post['categories'] = $content['categories'];
        }
        
        // convert to a Statamic entry
        $entry = $this->convertPostToEntry($post);

        $this->saveEntryToFile($entry, $content['_file']);

        return true;
    }
    
    //see http://codex.wordpress.org/XML-RPC_MetaWeblog_API#metaWeblog.deletePost
    function deletePost($params) {
        list($ignored, $postid, $username, $password) = $params;

        $this->authenticate($username, $password);
        
        // grab the page & the entry id
        list($page, $slug) = $this->parsePostId($postid);
        
        $path = $this->getFullPath($page, $slug);
        
        File::delete($path);
        // TODO: figure out how to throw an error back
        //$app = \Slim\Slim::getInstance();
        //$app->halt(401, 'Uh oh!');
        
        return true;
    }

    // see http://codex.wordpress.org/XML-RPC_MetaWeblog_API#metaWeblog.getCategories
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

    // see http://codex.wordpress.org/XML-RPC_MetaWeblog_API#metaWeblog.getUsersBlogs
    function getUsersBlogs($params) {
        list($username, $password) = $params;
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

    function supportedTextFilters($params) {
        return array();
    }
}