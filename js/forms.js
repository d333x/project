// Улучшенное поведение форм с плавающими метками
document.addEventListener('DOMContentLoaded', function() {
    // Обработка всех полей ввода
    const inputs = document.querySelectorAll('input, textarea');
    
    inputs.forEach(function(input) {
        // Проверяем, есть ли уже значение при загрузке страницы
        checkInputValue(input);
        
        // Обработчики событий
        input.addEventListener('input', function() {
            checkInputValue(this);
        });
        
        input.addEventListener('focus', function() {
            this.parentElement.classList.add('focused');
        });
        
        input.addEventListener('blur', function() {
            this.parentElement.classList.remove('focused');
            checkInputValue(this);
        });
    });
    
    function checkInputValue(input) {
        const formGroup = input.parentElement;
        if (input.value.trim() !== '') {
            formGroup.classList.add('has-value');
        } else {
            formGroup.classList.remove('has-value');
        }
    }
    
    // Автоматическое изменение размера textarea
    const textareas = document.querySelectorAll('textarea');
    textareas.forEach(function(textarea) {
        // Функция для автоматического изменения размера
        function autoResize() {
            textarea.style.height = 'auto';
            textarea.style.height = Math.max(textarea.scrollHeight, 120) + 'px';
        }
        
        textarea.addEventListener('input', autoResize);
        
        // Инициализация высоты при загрузке
        if (textarea.value.trim() !== '') {
            autoResize();
        }
    });
});