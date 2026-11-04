const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');

const resources = path.resolve(__dirname, '../resources/novels');
const directory = path.join(resources, 'swordmasters-youngest-son');
const glossary = JSON.parse(fs.readFileSync(path.join(resources, 'editorial-glossary.json'), 'utf8'));
const patches = JSON.parse(fs.readFileSync(path.join(resources, 'editorial/reviewed-patches.json'), 'utf8'));
const repairs = JSON.parse(fs.readFileSync(path.join(resources, 'editorial/paragraph-repairs.json'), 'utf8'));
const escape = (text) => text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
const aliases = new Map();
for (const [canonical, variants] of Object.entries(glossary)) {
    for (const variant of variants) {
        if (aliases.has(variant) && aliases.get(variant) !== canonical) throw Error(`Conflicting alias: ${variant}`);
        aliases.set(variant, canonical);
    }
}
const pattern = new RegExp(`(?<![\\p{L}\\p{N}_])(?:${[...aliases.keys()].sort((a, b) => b.length - a.length).map(escape).join('|')})(?![\\p{L}\\p{N}_])`, 'gu');

function normalizeNames(text) {
    return text.replace(pattern, (match) => aliases.get(match));
}

function typography(text) {
    return text.normalize('NFC').replace(/[\u200b\ufeff]/g, '')
        .replace(/[ \t\u00a0]+/g, ' ')
        .replace(/ +([,.;:!?])/g, '$1')
        .replace(/([“‘]) +/g, '$1')
        .replace(/ +([”’])(?=[\s.,;:!?]|$)/g, '$1')
        .trim();
}

function reviseParagraph(text, number, counts) {
    const patch = patches.find((patch) => patch.chapter === number && patch.original === text);
    if (patch) { text = patch.revised; counts.patches++; }
    text = text.replace(pattern, (match) => { counts.names++; return aliases.get(match); });
    // These expressions identify Vahn explicitly, rather than changing all brothers or all gods.
    text = text.replace(/\bBrother Vahn\b/g, 'Sister Vahn')
        .replace(/\bbrother Vahn\b/g, 'sister Vahn')
        .replace(/\bGod Vahn\b/g, 'Goddess Vahn');
    return typography(text);
}

function main() {
    const indexPath = path.join(directory, 'index.json');
    const index = JSON.parse(fs.readFileSync(indexPath, 'utf8'));
    if (index.edition !== 'original') throw Error('Regenerate the original corpus before applying editorial changes.');
    const planned = [], audit = [];
    for (const number of index.chapters) {
        const filename = path.join(directory, `${number}.json`);
        const input = fs.readFileSync(filename, 'utf8');
        const data = JSON.parse(input);
        const originals = data.paragraphs;
        const counts = { names: 0, patches: 0 };
        for (const patch of patches.filter((patch) => patch.chapter === number)) {
            if (data.paragraphs.filter((text) => text === patch.original).length !== 1) {
                throw Error(`Patch anchor missing or ambiguous in chapter ${number}: ${patch.original.slice(0, 80)}`);
            }
        }
        const joins = new Map();
        for (const repair of repairs.filter((repair) => repair.chapter === number)) {
            const positions = data.paragraphs.map((text, i) => text === repair.first && data.paragraphs[i + 1] === repair.second ? i : -1).filter((i) => i >= 0);
            if (positions.length !== 1) throw Error(`Paragraph repair anchor missing or ambiguous in chapter ${number}`);
            joins.set(positions[0], repair.separator);
        }
        const revised = data.paragraphs.map((text) => reviseParagraph(text, number, counts));
        const paragraphs = [];
        const translatorNotes = [];
        const chaptersWithFooterNotes = new Set([1047, 1050, 1052, 1066, 1068, 1069, 1074]);
        let removed = 0;
        for (let i = 0; i < revised.length; i++) {
            let text = revised[i];
            while (joins.has(i)) { text += joins.get(i) + revised[i + 1]; i++; }
            text = typography(text.replace(pattern, (match) => { counts.names++; return aliases.get(match); }));
            if (chaptersWithFooterNotes.has(number) && /^Note:/.test(text) && revised.length - i <= 8) {
                translatorNotes.push(...revised.slice(i));
                removed += revised.length - i;
                break;
            }
            // Keep scene breaks and narration. Remove only identifiable translation prompts/support footers.
            if (/^(?:Here is|Here's|This is|The translation of your|This translation may not be perfect)/.test(text) && /translation.*(?:English|Korean|best effort)|English translation/i.test(text)) { removed++; continue; }
            if (/^https?:\/\/(?:sociabuzz\.com\/sooyyb\/support|ko-fi\.com\/thatsoyb)/.test(text)) { removed++; continue; }
            if (text === 'or' && /^https?:\/\/sociabuzz\.com/.test(revised[i + 1] ?? '')) { removed++; continue; }
            if (text.startsWith('I can’t come up with a better translation other than that, if only this novel put hanja')) { removed++; continue; }
            paragraphs.push(text);
        }
        const text = paragraphs.join('\n\n');
        if (text.length < 2000 || /\uFFFD/.test(text)) throw Error(`Invalid chapter ${number}`);
        data.paragraphs = paragraphs;
        if (translatorNotes.length) data.translator_notes = translatorNotes;
        data.edition = 'reviewed';
        const output = JSON.stringify(data);
        planned.push([filename, output + '\n']);
        audit.push({ number, sourceParagraphs: originals.length,
            outputParagraphs: paragraphs.length, repairedBreaks: joins.size, removedNonNarrative: removed,
            changes: revised.filter((text, i) => text !== originals[i]).length,
            namedCorrections: counts.names, reviewedParagraphs: counts.patches,
            beforeSha256: crypto.createHash('sha256').update(input).digest('hex'),
            afterSha256: crypto.createHash('sha256').update(output + '\n').digest('hex') });
    }
    if (audit.reduce((sum, chapter) => sum + chapter.reviewedParagraphs, 0) !== patches.length) throw Error('Some patches were not applied.');
    // Validate every change before writing the first chapter.
    for (const [filename, content] of planned) fs.writeFileSync(filename, content);
    index.edition = 'reviewed';
    fs.writeFileSync(indexPath, JSON.stringify(index, null, 2) + '\n');
    fs.writeFileSync(path.join(directory, 'editorial-audit.json'), JSON.stringify(audit, null, 2) + '\n');
    console.log(JSON.stringify({ chapters: audit.length, paragraphsChanged: audit.reduce((n, c) => n + c.changes, 0), names: audit.reduce((n, c) => n + c.namedCorrections, 0), reviewedParagraphs: patches.length }));
}

module.exports = { normalizeNames, typography, reviseParagraph };
if (require.main === module) main();
