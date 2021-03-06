/*
Copyright (c) 2009 Yahoo! Inc. All rights reserved.
The copyrights embodied in the content of this file are licensed under the BSD (revised) open source license
*/
var widget = document.getElementById('widget'),//the dom element to contain widget (req'd)
	guid = null,
	launchPopup = function () {//launch a popup in which the user can authorize widget via Yahoo!
			var params = 'toolbar=0,scrollbars=1,location=1,statusbar=1,menubar=0,resizable=1,width=800,height=650,left=450,top=250',
				third_party_callback = encodeURIComponent('{this domain}/docs_oauth_reseller/client/proxy.php}'),
				popup_url = '{reseller domain}/docs_oauth_reseller/service/index.php?third_party_callback='+third_party_callback,
				popup = window.open(popup_url, 'auth', params),
				interval = setInterval(function(){//After spawning popup, 
					guid = YAHOO.util.Cookie.get("yahoo_guid");//periodically check for existence of guid cookie.
					if(guid){//When the guid's found, 
						clearInterval(interval);//stop checking, 
						var authButton = widget.getElementsByTagName('a')[0],
							authConf = widget.getElementsByTagName('span')[0];
						authButton.style.display = 'none';//hide auth button, 
						authConf.style.display = 'block';//show confirmation message, and 
						popup.close();//close the popup.
					}
				}, 1000);
		},
	postComment = function () {//when the comment is posted, this fn gets called.
		if(!guid){//if the auth token is not set, we can't publish updates.
			return;
		}
		var href = encodeURIComponent(document.location.href),
			title = ' left a comment at '+href,//the title of the update
			text = widget.getElementsByTagName('textarea')[0].value,
			description = encodeURIComponent(text.substr(0,9)+'...'),//set body text of update as 1st 10 char from comment + elipses 
			params = 'link='+href+'&title='+title+'&description='+description+'&guid='+encodeURIComponent(guid),//post params
			callback = {//callback fn for yui request util
				success: function(o){
					//successful request
				},
				failure: function(o){}
			};
		YAHOO.util.Connect.asyncRequest('POST', 'proxy.php?publish', callback, params);//make asynch post req to tell server to publish update
	},
	buildWidget = function () {//construct html for the widget & insert into container defined above
		var html =''//start w/ an empty str so we can concatenate w/o disturbing html indentation 
			+'<span style="display:'+(guid ? 'block' : 'none')+'">publishing updates to Yahoo!</span>'//if guid's set, show conf msg
			+'<a style="display:'+(guid ? 'none' : 'block')+'" href="#" onclick="launchPopup(); return false;">'//else, show auth button
				+'<img src="http://l.yimg.com/a/i/ydn/social/updt-spurp.png" style="border-width:0px;"/>'
			+'</a>'
			+'<form>'//the actual comment box
				+'<textarea></textarea><br/>'
				+'<button onclick="postComment(); return false">Post Comment</button>'
			+'</form>';
		widget.innerHTML = html;
		widget.style.display = 'block';//kludge: ff can't find div w/o content, so we reveal widget only after replacing dummy content
	},
	loader = new YAHOO.util.YUILoader({//using yui loader to ensure yui fns aren't called before they're loaded
	    require: ["connection", "cookie"],//load xhr and cookie utils
	    loadOptional: true,
	    onSuccess: function() {//after libs are loaded, 
			guid = YAHOO.util.Cookie.get("yahoo_guid");//fetch guid cookie, which may not be set, and 
			buildWidget();//initialize widget
	    },
	    timeout: 100000,
	    combine: true
	});
loader.insert();