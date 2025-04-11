$(document).ready(function(){
   var _btnBackToTop = $(".back-to-top");
   /*$(window).scroll(function () {
     var _scrollTop = $(window).scrollTop();
     
     if (_scrollTop > 70) {
         $(".main-menu").addClass("fix-nav");
		 $(".caudoi").addClass("fix");
          _btnBackToTop.removeClass("hide");
         return false;
     } else {
         $(".main-menu").removeClass("fix-nav");
		 $(".caudoi").removeClass("fix");
         _btnBackToTop.addClass("hide");
         return false;
     }
   });*/
   _btnBackToTop.click(function () {
      $('body,html').animate({ scrollTop: 0 }, 500);
   }); 
   $('.format-currency').on('keyup', function(e) {
      //e.prentDefault();
      var $this = $(this);
      var fomarted = formatNumber($this.val());
      $this.val(fomarted);
   });
   

});