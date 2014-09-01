<?php
require_once 'vendor/IXR_Library.php';

// used to hold an entry
class Entry
{
	public $title = '';
	public $categories = array();
	public $tags = array();
	public $content = '';
	public $post_status = 'publish';
	public $link = null;
	public $author = null;
}

class Hooks_xmlrpc extends Hooks
{
	private $link_custom_field;
	private $current_user;
	
    public function xmlrpc__rsd() {

        $siteURL = Config::getSiteURL();
        echo <<<RSD
<?xml version="1.0" encoding="UTF-8"?>
<rsd version="1.0" xmlns="http://archipelago.phrasewise.com/rsd">
<service>
<engineName>Statamic XML-RPC</engineName>
<engineLink>https://github.com/edalzell/statamic-xmlrpc/</engineLink>
<homePageLink>$siteURL</homePageLink>
<apis>
<api name="MetaWeblog" blogID="blog" preferred="true" apiLink="$siteURL/TRIGGER/xmlrpc/api" />
<api name="Blogger" blogID="blog" preferred="false" apiLink="$siteURL/TRIGGER/xmlrpc/api" />
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

		// register the callback and process the POST request
		$this->server = new IXR_Server(array(
					'metaWeblog.getPost' => array($this, 'getPost'),
					'metaWeblog.newPost'  => array($this, 'newPost'),
					'metaWeblog.editPost' => array($this, 'editPost'),
					'metaWeblog.deletePost' => array($this, 'deletePost'),
					'metaWeblog.getCategories' => array($this, 'getCategories'),
					'mt.getCategoryList' => array($this, 'getCategories'),
					'metaWeblog.getRecentPosts' => array($this, 'getRecentPosts'),
					'blogger.deletePost' => array($this, 'deletePost'),
					'mt.supportedTextFilters' => array($this, 'supportedTextFilters'),
					'mt.getPostCategories' => array($this, 'getPostCategories'),
					
					//Wordpress
					'wp.getUsersBlogs' => array($this, 'getUsersBlogs'),
				));
	}
	
	private function authenticate($username, $password) {
	    global $current_user;
	    
	    // attempt to load the Member object
        $user = Auth::getMember($username);
        
        // if no Member object, or checkPassword fails, return false
        if (!$user || !$user->checkPassword($password)) {
       		$app = \Slim\Slim::getInstance();
    		$app->halt(403, 'Incorrect username or password.');
        }
        
        $current_user = $username;
	}
	
	private function getUsername() {
	    global $current_user;
	    
	    return ($current_user);
	}
	
	// from a page and an entry slug, create the full path
	private function getFullPath($folder, $slug) {
	
		// create the file path
		$page_path = Path::resolve($folder . '/' . $slug);
		$path = Path::assemble(BASE_PATH, Config::getContentRoot(), $page_path . '.' . Config::getContentType());
		
		return $path;
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
			'dateCreated' => date('Y-m-d-Hi', $entry['datestamp']),
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
		
		if (isset($post['categories']) && (count($post['categories']) > 0)) {
			$entry->categories = $post['categories'];
		}
		
		if (isset($post['tags']) && (count($post['tags']) > 0)) {
			$entry->tags = $post['tags'];
		}
		
		if (isset($post['post_status'])) {
			$entry->post_status = $post['post_status'];
		}
		
		if (isset($post['custom_fields']) && (count($post['custom_fields']) > 0)) {
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
	
	// see spec here: http://codex.wordpress.org/XML-RPC_MetaWeblog_API#metaWeblog.getPost
	function getPost($params) {
	    list($postid, $username, $password) = $params;

		$this->authenticate($username, $password);
		
		// parse out the page & the entry id
		list($page, $slug) = $this->parsePostId($postid);
		
		// TODO - is this the right way to do this???
		$url = Config::getSiteRoot() . $page . '/' . $slug;

		//convert entry to MetaWeblog post
		return $this->convertEntryToPost(Content::get($url));
	}
	
	
	// see http://codex.wordpress.org/XML-RPC_MetaWeblog_API#metaWeblog.getRecentPosts
	function getRecentPosts($params) {
		$posts = array();
		
	    list($blog, $username, $password) = $params;
 
        $this->authenticate( $username, $password );
        		
		// grab the recent posts
		$content_set = ContentService::getContentByFolders($blog);

		// have to filter out everything that's NOT an entry
 		$content_set->filter(array( 'type' => 'entries' ));

        // sort entries newest to oldest
		$content_set->sort();
		
		// restrict to passed in limit.
		$content_set->limit($params[3]);
		
		//get content
		$content_array = $content_set->get( true, false );

		// loop through each entry and convert to a post
		$x = 0;
		foreach ($content_array as $entry) {
			$posts[$x++] = $this->convertEntryToPost($entry);	
		}
				
		return $posts;
	}
	
	// see http://codex.wordpress.org/XML-RPC_MetaWeblog_API#metaWeblog.newPost
	function newPost($params) {
		// pull data out of POST params
		// page is folder to save to
		list($page, $username, $password, $struct) = $params;
		
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
		$entry = $this->convertPostToEntry($struct);

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
		// pull data out of POST params
		// blogid is folder to save to
		list($postid, $username, $password, $struct, $publish) = $params;

        $this->authenticate($username, $password);
        
		list($page, $slug) = $this->parsePostId($postid);

		// get the entry data
		$entry = $this->convertPostToEntry($struct);

 		$fullpath = $this->getFullPath($page, $slug );

 		$this->saveEntryToFile($entry, $fullpath);

		return true;
	}
	
	//see http://codex.wordpress.org/XML-RPC_MetaWeblog_API#metaWeblog.deletePost
	function deletePost($params) {
	    list($garbase, $postid, $username, $password) = $params;

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
		$categories = array();
		
		$blog = $params[0];
		
		$taxonomy_set = ContentService::getTaxonomiesByType('categories');
		$taxonomy_set->sort();

		$taxonomies = $taxonomy_set->get();
				
		$x = 0;
		foreach ($taxonomies as $taxonomy) {
			$categories[$x++] = array(
				'categoryId' => $taxonomy['name'],
				'categoryName' => $taxonomy['name'],
				);
		}
		
		return $categories;
	}
	
	function getPostCategories($params) {
 		$categories = array();
		
		$postid = $params[0];
		
		// grab the page & the entry id
		list($page, $slug) = $this->parsePostId($postid);
		
		// grab the recent posts
		$content_set = ContentService::getContentByURL(URL::assemble($page, $slug));
		
		//get content
		$content_array = $content_set->get( false, false );

		$content = $content_array[0];

		foreach ($content['categories'] as $category) {
			$categories[] = array(
				'categoryId' => $category,
				'categoryName' => $category,
				'isPrimary' => false,
				);
		}
		
		return $categories;
	}
	
	function supportedTextFilters($params) {
		$textFilters = array();
		
		return $textFilters;
	}
	
	function getUsersBlogs( $params ) {
	    $this->authenticate($params[0],$params[1]);
	    
	    // get only the top level folders
	    $folders = ContentService::getContentTree('/',1);
	    
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
	        $blogs[] = ($item);
	    }
	    
	    return $blogs;
	}
}