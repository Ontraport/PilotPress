(function() {
   tinymce.create('tinymce.plugins.addloginform', {
      init : function(ed, url) {
         ed.addButton('addloginform', {
            title : 'Add Login Form',
            image : url+'/login-button.png',
            onclick : function() {
               jQuery('.pp-lf-form').show();
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
            authorurl : 'http://www.ontraport.com',
            infourl : 'http://www.ontraport.com',
            version : "1.0"
         };
      }
   });
   tinymce.PluginManager.add('addloginform', tinymce.plugins.addloginform);
})();