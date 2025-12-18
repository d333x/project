// optimization.js
document.addEventListener('DOMContentLoaded', function() {
    // Оптимизация анимаций
    const optimizeAnimations = () => {
        const elements = document.querySelectorAll('.optimized-float, .optimized-glass');
        
        // Регулируем частоту кадров в зависимости от нагрузки
        let lastTime = 0;
        const frameRate = 60;
        const interval = 1000 / frameRate;
        
        const animate = (time) => {
            if (time - lastTime > interval) {
                elements.forEach(el => {
                    // Лёгкие операции для поддержания плавности
                    const rect = el.getBoundingClientRect();
                    el.style.opacity = 1 - (rect.top / window.innerHeight * 0.2);
                });
                lastTime = time;
            }
            requestAnimationFrame(animate);
        };
        
        if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            requestAnimationFrame(animate);
        }
    };

    // Ленивая загрузка элементов
    const lazyLoad = () => {
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const target = entry.target;
                    target.classList.add('visible');
                    observer.unobserve(target);
                }
            });
        }, { threshold: 0.1 });

        document.querySelectorAll('[data-lazy]').forEach(el => {
            observer.observe(el);
        });
    };

    optimizeAnimations();
    lazyLoad();

    // Оптимизация scroll-событий
    let ticking = false;
    window.addEventListener('scroll', () => {
        if (!ticking) {
            window.requestAnimationFrame(() => {
                // Лёгкие операции при скролле
                ticking = false;
            });
            ticking = true;
        }
    }, { passive: true });
});