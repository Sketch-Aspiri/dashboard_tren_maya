import assert from 'node:assert/strict';
import { test } from 'node:test';
import { registrarCambiosSinGuardar } from '../../resources/js/cambios-sin-guardar.js';

function entorno(formularios) {
    const oyentes = {};
    const doc = {
        addEventListener: (tipo, fn) => (oyentes[tipo] = fn),
        querySelectorAll: () => formularios,
    };
    const win = { addEventListener: (tipo, fn) => (oyentes[tipo] = fn) };
    registrarCambiosSinGuardar(doc, win);

    return {
        escribir: (form) => oyentes.input({ target: { closest: () => form } }),
        enviar: (form, prevenido = false) => oyentes.submit({ target: form, defaultPrevented: prevenido }),
        salir: () => {
            let bloqueado = false;
            oyentes.beforeunload({ preventDefault: () => (bloqueado = true) });

            return bloqueado;
        },
    };
}

const formulario = (inicial = '') => ({ dataset: { avisoCambios: inicial } });

test('sin cambios deja salir', () => {
    assert.equal(entorno([formulario()]).salir(), false);
});

test('al escribir avisa antes de salir', () => {
    const form = formulario();
    const e = entorno([form]);
    e.escribir(form);
    assert.equal(e.salir(), true);
});

test('guardar el mismo formulario no avisa', () => {
    const form = formulario();
    const e = entorno([form]);
    e.escribir(form);
    e.enviar(form);
    assert.equal(e.salir(), false);
});

test('enviar otro formulario (subir foto) sí avisa', () => {
    const form = formulario();
    const e = entorno([form]);
    e.escribir(form);
    e.enviar({ dataset: {} });
    assert.equal(e.salir(), true);
});

test('un envío cancelado conserva el aviso', () => {
    const form = formulario();
    const e = entorno([form]);
    e.escribir(form);
    e.enviar(form, true);
    assert.equal(e.salir(), true);
});

test('arranca con aviso cuando la página trae datos sin guardar', () => {
    assert.equal(entorno([formulario('1')]).salir(), true);
});
