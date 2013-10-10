(function() {

   tinymce.create('tinymce.plugins.addloginform', {
      init : function(ed, url) {
         ed.addButton('addloginform', {
            title : 'Add Login Form',
            image : url+'/login-button.png',
            onclick : function() {
               
               //var posts = prompt("This is some random text.");
               //var text = prompt("List Heading", "This is the heading text");

               if (text != null && text != ''){
                  if (posts != null && posts != '')
                     ed.execCommand('mceInsertContent', false, '[login_page posts="'+posts+'"]');
                  else
                     ed.execCommand('mceInsertContent', false, '[login_page]');
               }
               else{
                  if (posts != null && posts != '')
                     ed.execCommand('mceInsertContent', false, '[login_page posts="'+posts+'"]');
                  else
                     ed.execCommand('mceInsertContent', false, '[login_page]');
               }
            }
         });
      },
      createControl : function(n, cm) {
         return null;
      },
      getInfo : function() {
         return {
            longname : "Add Login Form",
            author : 'William DeAngelis',
            authorurl : 'http://www.williamdeangelis.com',
            infourl : 'http://www.ontraport.com',
            version : "1.0"
         };
      }
   });
   tinymce.PluginManager.add('addloginform', tinymce.plugins.addloginform);
})();


