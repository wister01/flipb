(() => {
    const scene = document.querySelector('.parallax-scene');
    const layers = [...document.querySelectorAll('.parallax')];

    if (!scene || !layers.length) return;

    const depth = [18, 32, 48];

    let targetX = 0;
    let targetY = 0;
    let currentX = 0;
    let currentY = 0;
    let frame = null;

    function updateTarget(clientX, clientY) {
        const rect = scene.getBoundingClientRect();

        targetX = ((clientX - rect.left) / rect.width) * 2 - 1;
        targetY = ((clientY - rect.top) / rect.height) * 2 - 1;

        if (!frame) {
            frame = requestAnimationFrame(animate);
        }
    }

    function animate() {

        currentX += (targetX - currentX) * 0.08;
        currentY += (targetY - currentY) * 0.08;

        layers.forEach((layer, index) => {
            const amount = depth[index] ?? 24;

            const x = currentX * amount;
            const y = currentY * (amount * 0.55);

            layer.style.transform =
                `translate3d(calc(-15% + ${x}px), ${y}px, 0)`;
        });

        const stillMoving =
            Math.abs(targetX - currentX) > 0.001 ||
            Math.abs(targetY - currentY) > 0.001;

        if (stillMoving) {
            frame = requestAnimationFrame(animate);
        } else {
            frame = null;
        }
    }


    scene.addEventListener('pointermove', (event) => {
        updateTarget(event.clientX, event.clientY);
    });


    scene.addEventListener('pointerleave', () => {
        targetX = 0;
        targetY = 0;

        if (!frame) {
            frame = requestAnimationFrame(animate);
        }
    });
})();