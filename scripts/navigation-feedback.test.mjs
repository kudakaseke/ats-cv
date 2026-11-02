import {test} from 'node:test';
import assert from 'node:assert/strict';
import {isPageNavigation} from '../resources/js/navigation-feedback.js';
const current='https://example.test/login';
const click={button:0};
const link=(href,attributes={})=>({target:attributes.target??'',hasAttribute:key=>key in attributes,getAttribute:key=>key==='href'?href:null});
test('ordinary local navigation and OAuth handoff show feedback',()=>{
 for(const href of ['/','/about','/register','/auth/google'])assert.equal(isPageNavigation(click,link(href),current),true);
});
test('feedback does not interfere with modified clicks, downloads, external links or anchors',()=>{
 for(const modifier of ['ctrlKey','metaKey','shiftKey','altKey','defaultPrevented'])assert.equal(isPageNavigation({...click,[modifier]:true},link('/'),current),false);
 assert.equal(isPageNavigation({button:1},link('/'),current),false);
 for(const href of ['#main','/login#form','https://google.com','mailto:hello@example.test','javascript:void(0)'])assert.equal(isPageNavigation(click,link(href),current),false);
 assert.equal(isPageNavigation(click,link('/',{download:''}),current),false);
 assert.equal(isPageNavigation(click,link('/',{target:'_blank'}),current),false);
});
