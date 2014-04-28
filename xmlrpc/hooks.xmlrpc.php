<?php
require_once 'vendor/IXR_Library.php';

// used to hold an entry
class Entry
{
	public $title = '';
	public $categories = array();
	public $tags = array();
	public $content = '';
}

class Hooks_metaweblog extends Hooks
{
	private $server;
	
	public function metaweblog__api()
	{
		// register the callback and process the POST request
		$this->server = new IXR_Server(array(
					// Metaweblog API
					'metaWeblog.getPost' => array($this, 'getPost'),
					'metaWeblog.newPost'  => array($this, 'newPost'),
					'metaWeblog.editPost' => array($this, 'editPost'),
					'metaWeblog.deletePost' => array($this, 'deletePost'),
					'metaWeblog.getCategories' => array($this, 'getCategories'),
					'metaWeblog.getRecentPosts' => array($this, 'getRecentPosts'),
					// Blogger API
					'blogger.deletePost' => array($this, 'deletePost'),
			
					// WP API - I don't dare do it!
				));
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
	
	// create the structure required for the MetaWeblog API
	private function convertEntryToPost($entry) {
		$post = array(
			'postid' => $this->makePostId($entry['_folder'], $entry['slug']),
			'title' =>  $entry['title'],
			'description' => $entry['content_raw'],
			'link' => $entry['url'],
			'permaLink' => $entry['permalink'],
			'categories' => $entry['categories'],
			'dateCreated' => date('Y-m-d', $entry['datestamp']),
		);	
		return $post;
	}
	
	private function convertPostToEntry($params) {
		$entry = new Entry();

		// get the entry data
		$entry->title = $params['title'];
		
		if (isset($params['description'])) {
			$entry->content = $params['description'];
		}
		
		if (isset($params['categories']) && (count($params['categories']) > 0)) {
			$entry->categories = $params['categories'];
		}
		
		if (isset($params['tags']) && (count($params['tags']) > 0)) {
			$entry->tags = $params['tags'];
		}
		
		return $entry;
	}

	private function saveEntryToFile($entry, $path) {
	
		// Front matter
		$data = array(
		  'title' => $entry->title,
		  'categories' => $entry->categories,
		  'tags' => $entry->tags,
		);

		// write the file
 		File::put($path, File::buildContent($data, $entry->content));
	}
	
	// see spec here: http://codex.wordpress.org/XML-RPC_MetaWeblog_API#metaWeblog.getPost
	function getPost($params) {
	
		//grab the post id
		$postid = $params[0];
		
		// parse out the page & the entry id
		list($page, $slug) = $this->parsePostId($postid);
		
		// TODO - is this the right to do this???
		$url = Config::getSiteRoot() . $page . '/' . $slug;

		//convert entry to MetaWeblog post
		return $this->convertEntryToPost(Content::get($url));
	}
	
	
	// see http://codex.wordpress.org/XML-RPC_MetaWeblog_API#metaWeblog.getRecentPosts
	function getRecentPosts($params) {
		$posts = array();
		
 		$blog = $params[0];
		
		// grab the recent posts
		$content_set = ContentService::getContentByFolders($blog);
		$content_set->limit($params[3]);
		
		// have to filter out everything that's NOT an entry
 		$content_set->filter(array( 'type' => 'entries' ));

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
		list($page, $username, $password, $struct, $publish) = $params;

		// create the file path
		$page_path = Path::resolve($page);
		
		// create the appropriate prefix
		$entry_type = Statamic::get_entry_type($page_path);

		$prefix = "";
 		if ($entry_type == 'date') {
 			// TODO: check the _entry_timestamps config to determine how to name the file
 			$prefix = date('Y-m-d-');
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

		list($page, $slug) = $this->parsePostId($postid);

		// get the entry data
		$entry = $this->convertPostToEntry($struct);

 		$fullpath = $this->getFullPath($page, $slug );

 		$this->saveEntryToFile($entry, $fullpath);

		return true;
	}
	
	//see http://codex.wordpress.org/XML-RPC_MetaWeblog_API#metaWeblog.deletePost
	function deletePost($params) {
		$postid = $params[1];
		
		// grab the page & the entry id
		list($page, $slug) = $this->parsePostId($postid);
		
		$path = $this->getFullPath($page, $slug);
		
		File::delete($path);
		// TODO: figure out how to throw an error back
		//$this->server->error(404, 'Could not find post ' . $postid );
		
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
				'parentId' => '0',
				'categoryName' => $taxonomy['name'],
				);
		}
		
		return $categories;
	}
}
?>