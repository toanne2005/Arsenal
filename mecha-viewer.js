(() => {
    const container = document.getElementById('mecha-viewer');
    if (!container) return;

    const loading = container.querySelector('.mecha-viewer-loading');
    const fail = error => {
        console.error('Không thể tải mô hình Enceladus:', error);
        loading.textContent = 'KHÔNG THỂ TẢI MÔ HÌNH 3D';
        loading.classList.add('mecha-viewer-error');
    };

    if (!window.THREE || !window.THREE.GLTFLoader) {
        fail(new Error('Three.js hoặc GLTFLoader chưa được tải.'));
        return;
    }

    const THREE = window.THREE;
    const scene = new THREE.Scene();
    const camera = new THREE.PerspectiveCamera(32, 1, 0.1, 1000);
    const renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true });
    renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
    renderer.outputEncoding = THREE.sRGBEncoding;
    renderer.toneMapping = THREE.ACESFilmicToneMapping;
    renderer.toneMappingExposure = 1.05;
    renderer.shadowMap.enabled = true;
    renderer.shadowMap.type = THREE.PCFSoftShadowMap;
    container.appendChild(renderer.domElement);

    scene.add(new THREE.HemisphereLight(0xdceaff, 0x17120a, 1.15));

    const keyLight = new THREE.DirectionalLight(0xfff1d6, 2.5);
    keyLight.position.set(4, 8, 7);
    keyLight.castShadow = true;
    keyLight.shadow.mapSize.set(1024, 1024);
    scene.add(keyLight);

    const rimLight = new THREE.PointLight(0x287cff, 20, 18);
    rimLight.position.set(-5, 3, -4);
    scene.add(rimLight);

    const goldLight = new THREE.PointLight(0xd4af37, 9, 14);
    goldLight.position.set(4, -1, 4);
    scene.add(goldLight);

    const modelPivot = new THREE.Group();
    scene.add(modelPivot);

    let modelHeight = 5;
    let cameraDistance = 10;
    let targetRotation = -Math.PI / 2;
    let dragging = false;
    let lastPointerX = 0;
    let frameId = 0;

    const floor = new THREE.Mesh(
        new THREE.PlaneGeometry(200, 200),
        new THREE.ShadowMaterial({ opacity: 0.2 })
    );
    floor.rotation.x = -Math.PI / 2;
    floor.position.y = -modelHeight / 2;
    floor.receiveShadow = true;
    scene.add(floor);

    function fitCamera() {
        const width = container.clientWidth;
        const height = container.clientHeight;
        if (!width || !height) return;

        camera.aspect = width / height;
        camera.updateProjectionMatrix();
        renderer.setSize(width, height);

        const verticalFov = THREE.MathUtils.degToRad(camera.fov);
        const horizontalFov = 2 * Math.atan(Math.tan(verticalFov / 2) * camera.aspect);
        cameraDistance = Math.max(
            modelHeight / (2 * Math.tan(verticalFov / 2)),
            modelHeight / (2 * Math.tan(horizontalFov / 2))
        ) * 1.22;
        camera.position.set(cameraDistance * 0.12, modelHeight * 0.08, cameraDistance);
        camera.lookAt(0, 0, 0);
    }

    container.addEventListener('pointerdown', event => {
        dragging = true;
        lastPointerX = event.clientX;
        container.setPointerCapture(event.pointerId);
    });

    container.addEventListener('pointermove', event => {
        if (!dragging) return;
        targetRotation += (event.clientX - lastPointerX) * 0.008;
        lastPointerX = event.clientX;
    });

    const stopDragging = () => {
        dragging = false;
    };
    container.addEventListener('pointerup', stopDragging);
    container.addEventListener('pointercancel', stopDragging);
    container.addEventListener('lostpointercapture', stopDragging);

    const resizeObserver = new ResizeObserver(fitCamera);
    resizeObserver.observe(container);

    const loader = new THREE.GLTFLoader();
    loader.load(
        'assets/models/model.glb',
        gltf => {
            const object = gltf.scene;
            const bounds = new THREE.Box3().setFromObject(object);
            const size = bounds.getSize(new THREE.Vector3());
            const center = bounds.getCenter(new THREE.Vector3());
            const largestDimension = Math.max(size.x, size.y, size.z);

            if (!Number.isFinite(largestDimension) || largestDimension <= 0) {
                fail(new Error('Mô hình 3D không có kích thước hợp lệ.'));
                return;
            }

            const centeredModel = new THREE.Group();
            centeredModel.add(object);
            object.position.sub(center);
            centeredModel.scale.setScalar(5 / largestDimension);
            object.traverse(child => {
                if (!child.isMesh) return;
                child.castShadow = true;
                child.receiveShadow = true;
            });

            modelHeight = Math.max(size.y, size.x, size.z) * (5 / largestDimension);
            floor.position.y = -modelHeight / 2;
            modelPivot.add(centeredModel);
            fitCamera();
            container.classList.add('mecha-viewer-ready');
            loading.remove();
        },
        event => {
            if (event.lengthComputable && event.total > 0) {
                const percent = Math.round((event.loaded / event.total) * 100);
                loading.textContent = `ĐANG TẢI MÔ HÌNH 3D... ${percent}%`;
            }
        },
        fail
    );

    const clock = new THREE.Clock();
    function animate() {
        frameId = window.requestAnimationFrame(animate);
        const elapsed = clock.getElapsedTime();
        if (!dragging) targetRotation += 0.0012;
        modelPivot.rotation.y += (targetRotation - modelPivot.rotation.y) * 0.055;
        modelPivot.position.y = Math.sin(elapsed * 0.8) * 0.025;
        renderer.render(scene, camera);
    }

    animate();
    document.addEventListener('visibilitychange', () => {
        if (document.hidden && frameId) {
            window.cancelAnimationFrame(frameId);
            frameId = 0;
        } else if (!document.hidden && !frameId) {
            animate();
        }
    });
})();
