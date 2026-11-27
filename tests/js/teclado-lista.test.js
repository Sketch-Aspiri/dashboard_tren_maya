import assert from 'node:assert/strict';
import { test } from 'node:test';
import { desplazamiento } from '../../resources/js/movimiento.js';
import { siguienteEnLista } from '../../resources/js/teclado-lista.js';

test('desde el campo, abajo va al primero y arriba al último', () => {
    assert.equal(siguienteEnLista('ArrowDown', -1, 3), 0);
    assert.equal(siguienteEnLista('ArrowUp', -1, 3), 2);
});

test('en la lista da la vuelta hacia abajo y regresa al campo hacia arriba', () => {
    assert.equal(siguienteEnLista('ArrowDown', 2, 3), 0);
    assert.equal(siguienteEnLista('ArrowUp', 0, 3), -1);
});

test('Inicio y Fin solo se atienden dentro de la lista', () => {
    assert.equal(siguienteEnLista('End', -1, 3), null);
    assert.equal(siguienteEnLista('End', 0, 3), 2);
    assert.equal(siguienteEnLista('Home', 2, 3), 0);
});

test('sin resultados o con otra tecla no hace nada', () => {
    assert.equal(siguienteEnLista('ArrowDown', -1, 0), null);
    assert.equal(siguienteEnLista('a', -1, 3), null);
});

const raiz = (clase) => ({ classList: { contains: () => clase } });
const ventana = (reducir) => ({ matchMedia: () => ({ matches: reducir }) });

test('el desplazamiento deja de ser suave si se pide menos movimiento', () => {
    assert.equal(desplazamiento(raiz(false), ventana(false)), 'smooth');
    assert.equal(desplazamiento(raiz(true), ventana(false)), 'auto');
    assert.equal(desplazamiento(raiz(false), ventana(true)), 'auto');
});
