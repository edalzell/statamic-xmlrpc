statamic-xmlrpc
===============

A Statamic add-on that allows you to post from a MetaWeblog client, like MarsEdit. If you would like the author to show up, you need to add

## Installing
1. Copy the "_add-ons" folder contents to your Statamic root directory;
2. Do the same to the files inside the "_config" directory;
  > Just be careful to respect the exact folder structure, okay?
3. Configure the "xmlrpc.yaml" file with your custom values:
  * blog: the name of the folder used for your blog;
  * link_custom_field: the name of the MarsEdit custom field that will hold the link (linkblog) for a post;
  * autho_custom_field: name of the MarsEdit custom field that will hold the author. If not used, the signed in user is used
4. Hook up MarsEdit to you site. Use MetaWeblog as the type and http://yoursite.com/TRIGGER/xmlrpc/api as the endpoint.
5. Add the custom fields (be sure to use the same names as you did in the config file).

## What's missing:

* No support for tags.
* Cannot rename post.
* No support for hidden posts.
* No support for draft posts.