statamic-xmlrpc
===============

A Statamic add-on that allows you to post from a MoveableType client, like MarsEdit.

## Installing
1. Copy the "_add-ons" folder contents to your Statamic root directory;
2. Do the same to the files inside the "_config" directory;
  > Just be careful to respect the exact folder structure, okay?
3. Copy the xmlrpc.php file to the root of your site (this is so clients can auto-detect your configuration)
4. Configure the "xmlrpc.yaml" file with your custom values:
  * blog: the name of the folder used for your blog;
  * link_custom_field: the name of the MarsEdit custom field that will hold the link (linkblog) for a post;
  * author_custom_field: name of the MarsEdit custom field that will hold the author. If not used, the signed in user is used
5. Hook up MarsEdit to your site.
  * if you copied the xmlrpc.php file, everything **should** be detected properly.
  * if auto-detection doesn't work use Moveable Type as the type and http://yoursite.com/TRIGGER/xmlrpc/api as the endpoint.
6. Add the custom fields (be sure to use the same names as you did in the config file).

## What's missing:

* No support for tags.
* Cannot rename post.
* No support for hidden posts.

## LICENSE

[MIT License](http://emd.mit-license.org)