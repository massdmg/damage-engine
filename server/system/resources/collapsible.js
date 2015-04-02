
function addCollapsibleHandling()
{
  $(".collapsible")
    .before("<div class='expander' >show details</div>")
    .before("<div class='collapser'>hide details</div>")
     .after("<div class='collapser'>hide details</div>");
    
  $(".expander" ).click(function(){    expandCollapsible($(this).siblings(".collapsible"));  });
  $(".collapser").click(function(){  collapseCollapsible($(this).siblings(".collapsible"));  });
  
  expandCollapsible($(".collapsible").not(".collapsed"));
}

function expandCollapsible( collapsible )
{
  $(collapsible).slideDown("slow");
  $(collapsible).prevAll(".expander").first().hide();
  $(collapsible).prev(".collapser").show();
  $(collapsible).next(".collapser").show();
}

function collapseCollapsible( collapsible )
{
  $(collapsible).next(".collapser").hide();
  $(collapsible).prev(".collapser").hide();
  $(collapsible).prevAll(".expander").first().show();
  $(collapsible).slideUp("slow");
}