import assert from 'node:assert/strict';
import { test } from 'node:test';
import { calcularSeparacion } from '../../resources/js/flotante.js';

const vista = { ancho: 1280, alto: 800 };

test('sin obstáculos queda abajo', () => {
    assert.equal(calcularSeparacion([], vista), 16);
});

test('sube por encima de una barra pegada abajo', () => {
    assert.equal(calcularSeparacion([{ top: 728, bottom: 800, left: 0, right: 1280 }], vista), 84);
});

test('ignora barras que no llegan a la esquina', () => {
    assert.equal(calcularSeparacion([{ top: 728, bottom: 800, left: 250, right: 1030 }], vista), 16);
});

test('ignora barras fuera de la pantalla', () => {
    assert.equal(calcularSeparacion([{ top: 900, bottom: 970, left: 0, right: 1280 }], vista), 16);
});

test('salta dos obstáculos apilados', () => {
    const obstaculos = [
        { top: 750, bottom: 790, left: 1100, right: 1250 },
        { top: 660, bottom: 730, left: 0, right: 1280 },
    ];
    assert.equal(calcularSeparacion(obstaculos, vista), 152);
});

test('si no cabe encima, se queda abajo', () => {
    assert.equal(calcularSeparacion([{ top: 0, bottom: 800, left: 0, right: 1280 }], vista), 16);
});
