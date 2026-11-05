const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const { normalizeNames, typography } = require('./novel-editorial.cjs');
const directory = path.resolve(__dirname, '../resources/novels/swordmasters-youngest-son');
const chapter = (number) => JSON.parse(fs.readFileSync(path.join(directory, `${number}.json`), 'utf8'));

test('glossary matches complete names, preserving unrelated words and English imperatives', () => {
    assert.equal(normalizeNames('Jin Runkandel met Siron and Ban.'), 'Jin Runcandel met Chiron and Vahn.');
    assert.equal(normalizeNames('Banquet, vanilla, muon. Let him go.'), 'Banquet, vanilla, muon. Let him go.');
    assert.equal(normalizeNames('Run Kandel’s sword and Queen Rani'), 'Runcandel’s sword and Queen Lani');
});

test('typography preserves dialogue, internal thoughts and scene breaks', () => {
    assert.equal(typography('  “Hello ,  Jin !”  '), '“Hello, Jin!”');
    assert.equal(typography("'Runcandel’s sword...'"), "'Runcandel’s sword...'");
    assert.equal(typography('* * *'), '* * *');
});

test('all 747 chapters have audited, unique, nonempty narrative and stable terminology', () => {
    const index = JSON.parse(fs.readFileSync(path.join(directory, 'index.json'), 'utf8'));
    const audit = JSON.parse(fs.readFileSync(path.join(directory, 'editorial-audit.json'), 'utf8'));
    assert.equal(index.edition, 'reviewed');
    assert.deepEqual(index.chapters, Array.from({ length: 747 }, (_, i) => i + 401));
    assert.equal(audit.length, 747);
    const seen = new Set();
    for (const number of index.chapters) {
        const bytes = fs.readFileSync(path.join(directory, `${number}.json`));
        const data = JSON.parse(bytes.toString('utf8'));
        const item = audit.find((entry) => entry.number === number);
        const text = data.paragraphs.join('\n\n');
        assert.equal(data.number, number);
        assert.equal(data.edition, 'reviewed');
        assert.ok(text.length > 2000, `Empty chapter ${number}`);
        assert.ok(!text.includes('\uFFFD'), `Invalid Unicode ${number}`);
        assert.equal(normalizeNames(text), text, `Unnormalized name ${number}`);
        assert.equal(crypto.createHash('sha256').update(bytes).digest('hex'), item.afterSha256, `Audit mismatch ${number}`);
        assert.equal(data.paragraphs.length, item.sourceParagraphs - item.repairedBreaks - item.removedNonNarrative);
        assert.equal(seen.has(text), false, `Duplicate chapter ${number}`);
        seen.add(text);
        assert.ok(!data.paragraphs.some((p) => /^Here(?: is|'s) the English translation/.test(p)), `Translation prompt ${number}`);
    }
});

test('inserted paragraph marks no longer split words or known sentences', () => {
    assert.ok(chapter(401).paragraphs.includes("Lata couldn't be entirely angry at those words."));
    assert.ok(chapter(461).paragraphs.some((p) => p.includes('like Vigo and the executioners')));
    assert.ok(chapter(1007).paragraphs.some((p) => p.includes('Murakan handed Qwaul a handkerchief')));
    assert.ok(chapter(1112).paragraphs.some((p) => p.includes('subservient to the Demon Army.')));
    assert.ok(!chapter(1112).paragraphs.some((p) => p.includes('tThe Demon Army')));
});

test('804/805 preserve separate scenes, consistent titles and the branch established in 803', () => {
    const a = chapter(804), b = chapter(805);
    assert.match(a.paragraphs[1], /13th Battle King \(1\)/);
    assert.match(b.paragraphs[1], /13th Battle King \(2\)/);
    assert.ok(!a.paragraphs.some((p) => p.includes('7th branch')));
    assert.ok(a.paragraphs.some((p) => p.includes('3rd branch')));
    assert.ok(a.paragraphs.some((p) => p.includes("fleet's bombardment began")));
    assert.ok(b.paragraphs.some((p) => p.includes('To fend off the bombardment')));
    const aLong = new Set(a.paragraphs.filter((p) => p.length > 100));
    assert.equal(b.paragraphs.some((p) => aLong.has(p)), false);
});

test('contextual pronouns and translator notes stay separate from narration', () => {
    assert.ok(chapter(523).paragraphs.some((p) => p.includes('Talaris shook her head')));
    assert.ok(chapter(573).paragraphs.some((p) => p.startsWith('Jin nodded his head.')));
    assert.ok(chapter(882).paragraphs.some((p) => p.includes('renamed it Poison Demon Castle')));
    assert.ok(chapter(953).paragraphs.some((p) => p.startsWith('[You cannot stop me, Jin Runcandel.')));
    assert.ok(chapter(1074).translator_notes.length > 0);
    assert.ok(!chapter(1074).paragraphs.includes('Note:'));
});

test('wiki names normalize confirmed variants without conflating other people or places', () => {
    assert.equal(normalizeNames('Shirone Runcandle, Sirone, Shilon and Cyrone.'), 'Chiron Runcandel, Chiron, Chiron and Chiron.');
    assert.equal(normalizeNames('Zipple, Gliyak, Quicantel, Leuth, Paitel, Kidard and Paddler.'), 'Zipfel, Gliek, Quikantel, Lueth, Peitel, Keydart and Padler.');
    const distinct = 'Mitel, Draka, Draca, Luna, Diana, Free, Rok, Locke, Luke and Mureka. Let him go.';
    assert.equal(normalizeNames(distinct), distinct);
    for (const canonical of Object.keys(JSON.parse(fs.readFileSync(path.join(directory, '../editorial-glossary.json'), 'utf8')))) {
        assert.equal(normalizeNames(canonical), canonical, canonical);
    }
});
