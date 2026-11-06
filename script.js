const motionPreference = window.matchMedia("(prefers-reduced-motion: reduce)");
const prefersReducedMotion = motionPreference.matches;

function initializeCurtainMotion() {
	const sections = [...document.querySelectorAll("body > section")];
	const hasScrollTimeline = CSS.supports("animation-timeline: view()")
		&& CSS.supports("animation-range: exit 0% exit 100%");

	if (hasScrollTimeline) return;

	let frameRequested = false;
	const updateSections = () => {
		frameRequested = false;
		if (motionPreference.matches) {
			sections.forEach((section) => {
				section.style.removeProperty("transform");
			});
			return;
		}

		const overlap = Math.min(96, Math.max(32, window.innerHeight * 0.08));
		sections.forEach((section) => {
			const top = section.offsetTop - window.scrollY;
			const height = section.offsetHeight;
			const bottom = top + height;
			const progress = height <= window.innerHeight
				? -top / Math.max(height, 1)
				: (window.innerHeight - bottom) / window.innerHeight;
			const boundedProgress = Math.min(1, Math.max(0, progress));
			section.style.transform = `translateY(${-overlap * boundedProgress}px)`;
		});
	};
	const scheduleUpdate = () => {
		if (!frameRequested) {
			frameRequested = true;
			window.requestAnimationFrame(updateSections);
		}
	};

	window.addEventListener("scroll", scheduleUpdate, { passive: true });
	window.addEventListener("resize", scheduleUpdate);
	if (typeof motionPreference.addEventListener === "function") {
		motionPreference.addEventListener("change", scheduleUpdate);
	} else {
		motionPreference.addListener(scheduleUpdate);
	}
	scheduleUpdate();
}

initializeCurtainMotion();

function initializeMobileNavigation() {
	const links = [...document.querySelectorAll(".nav-item a[href^=\"#\"]")];
	const sections = links
		.map((link) => document.querySelector(link.getAttribute("href")))
		.filter(Boolean);
	if (!("IntersectionObserver" in window) || sections.length === 0) return;

	const heroSection = document.querySelector("#hero");
	if (heroSection) {
		const heroObserver = new IntersectionObserver(([entry]) => {
			document.body.classList.toggle("is-on-hero", entry.isIntersecting);
		}, { threshold: 0.1 });
		heroObserver.observe(heroSection);
	}

	const linksBySection = new Map(
		links.map((link) => [document.querySelector(link.getAttribute("href")), link])
	);
	const observer = new IntersectionObserver((entries) => {
		const activeEntry = entries
			.filter((entry) => entry.isIntersecting)
			.sort((first, second) => (
				Math.abs(first.boundingClientRect.top - window.innerHeight * 0.35)
				- Math.abs(second.boundingClientRect.top - window.innerHeight * 0.35)
			))[0];

		if (!activeEntry) return;
		links.forEach((link) => link.removeAttribute("aria-current"));
		linksBySection.get(activeEntry.target)?.setAttribute("aria-current", "location");
	}, {
		rootMargin: "-15% 0px -65% 0px",
		threshold: 0
	});

	sections.forEach((section) => observer.observe(section));
}

initializeMobileNavigation();

const revealObserver = !prefersReducedMotion && "IntersectionObserver" in window
	? new IntersectionObserver((entries, observer) => {
		entries.forEach((entry) => {
			if (entry.isIntersecting) {
				entry.target.classList.add("is-visible");
				observer.unobserve(entry.target);
			}
		});
	}, { threshold: 0.08, rootMargin: "0px 0px -6% 0px" })
	: null;

function observeRevealElements(root = document) {
	const targets = [
		...root.querySelectorAll("h1, h2, h3, p, [data-reveal=\"card\"]"),
		...(root.matches?.("h1, h2, h3, p, [data-reveal=\"card\"]") ? [root] : [])
	].filter((element) => !element.closest('[data-reveal="card"] :is(h1, h2, h3, p)'));

	targets.forEach((element) => {
		if (revealObserver) {
			revealObserver.observe(element);
		} else {
			element.classList.add("is-visible");
		}
	});
}

observeRevealElements();

const EVENTS_PER_PAGE = 4;
const FIRST_VISIBLE_DATE = "2026-11-26";
const LAST_VISIBLE_DATE = "2026-11-28";
const poloFilter = document.querySelector("#filter-polo");
const dateFilter = document.querySelector("#filter-date");
const eventList = document.querySelector("#event-list");
const programStatus = document.querySelector("#programacao-status");
const pagination = document.querySelector("#programacao-pagination");
const previousPageButton = document.querySelector("#previous-page");
const nextPageButton = document.querySelector("#next-page");
const paginationStatus = document.querySelector("#pagination-status");

let programData;
let currentPage = 1;

function createTextElement(tagName, className, text) {
	const element = document.createElement(tagName);
	element.className = className;
	element.textContent = text;
	return element;
}

function createEventCard(event, polo, date) {
	const card = document.createElement("article");
	card.className = "event-card";
	card.dataset.reveal = "card";

	const eventHeading = document.createElement("div");
	eventHeading.className = "event-heading";
	eventHeading.append(
		createTextElement("p", "event-date", date?.rotulo ?? ""),
		createTextElement("p", "event-time", event.hora)
	);
	card.append(eventHeading);
	const eventTitle = event.titulo.replace(
		/^Lançamento Literário\s*\|/i,
		"Lançamento literário |"
	);
	card.append(createTextElement("h3", "event-title", eventTitle));

	if (event.convidados.length > 0) {
		const guests = document.createElement("ul");
		guests.className = "event-guests";
		event.convidados.forEach((guest) => {
			guests.append(createTextElement("li", "", guest));
		});
		card.append(guests);
	}

	if (polo) {
		const venue = document.createElement("div");
		venue.className = "event-venue";
		venue.append(
			createTextElement("p", "event-polo", polo.nome),
			createTextElement("p", "event-location", polo.local)
		);
		card.append(venue);
	}

	return card;
}

function renderProgram() {
	if (!programData) return;

	const poloById = new Map(programData.polos.map((polo) => [polo.id, polo]));
	const dateById = new Map(programData.datas.map((date) => [date.id, date]));
	const filteredEvents = [...programData.programacao]
		.filter((event) => (
			(!poloFilter.value || event.poloId === poloFilter.value)
			&& (!dateFilter.value || event.data === dateFilter.value)
		))
		.sort((first, second) => (
			first.data.localeCompare(second.data) || first.hora.localeCompare(second.hora)
		));
	const totalPages = Math.ceil(filteredEvents.length / EVENTS_PER_PAGE);

	if (filteredEvents.length === 0) {
		eventList.replaceChildren();
		programStatus.textContent = "Nenhum evento encontrado para os filtros selecionados.";
		pagination.hidden = true;
		return;
	}

	const firstEventIndex = (currentPage - 1) * EVENTS_PER_PAGE;
	const pageEvents = filteredEvents.slice(firstEventIndex, firstEventIndex + EVENTS_PER_PAGE);
	const cards = pageEvents.map((event) => createEventCard(
		event,
		poloById.get(event.poloId),
		dateById.get(event.data)
	));

	eventList.replaceChildren(...cards);
	programStatus.textContent = "";
	pagination.hidden = totalPages <= 1;
	paginationStatus.textContent = `Página ${currentPage} de ${totalPages}`;
	previousPageButton.disabled = currentPage === 1;
	nextPageButton.disabled = currentPage === totalPages;
	observeRevealElements(eventList);
}

function populateFilters(data) {
	data.polos.forEach((polo) => {
		const option = document.createElement("option");
		option.value = polo.id;
		option.textContent = polo.nome;
		poloFilter.append(option);
	});

	data.datas
		.filter((date) => date.id >= FIRST_VISIBLE_DATE && date.id <= LAST_VISIBLE_DATE)
		.forEach((date) => {
			const option = document.createElement("option");
			option.value = date.id;
			option.textContent = date.rotulo;
			dateFilter.append(option);
		});

	poloFilter.disabled = false;
	dateFilter.disabled = false;
}

function hasExpectedDataShape(data) {
	return Boolean(
		data
		&& Array.isArray(data.polos)
		&& data.polos.every((polo) => polo && typeof polo.id === "string" && typeof polo.nome === "string" && typeof polo.local === "string")
		&& Array.isArray(data.datas)
		&& data.datas.every((date) => date && typeof date.id === "string" && typeof date.rotulo === "string")
		&& Array.isArray(data.programacao)
		&& data.programacao.every((event) => (
			event
			&& typeof event.id === "string"
			&& typeof event.poloId === "string"
			&& typeof event.data === "string"
			&& typeof event.hora === "string"
			&& typeof event.titulo === "string"
			&& Array.isArray(event.convidados)
			&& event.convidados.every((guest) => typeof guest === "string")
		))
	);
}

function normalizeProgramData(data) {
	if (
		!data
		|| !Array.isArray(data.polos)
		|| !data.polos.every((polo) => typeof polo === "string")
		|| !Array.isArray(data.datas)
		|| !data.datas.every((date) => typeof date === "string")
		|| !Array.isArray(data.programacao)
		|| !data.programacao.every((event) => (
			event
			&& typeof event.polo === "string"
			&& typeof event.data === "string"
			&& typeof event.horario === "string"
			&& typeof event.titulo === "string"
			&& typeof event.convidados === "string"
		))
	) {
		throw new Error("O arquivo data_v3.json não possui o formato esperado.");
	}

	const polos = data.polos.map((polo, index) => {
		const [nome, local, ...extraParts] = polo.split("|").map((part) => part.trim());
		if (!nome || !local || extraParts.length > 0) {
			throw new Error(`Polo inválido no arquivo data_v3.json: ${polo}`);
		}

		return { id: `polo-${index + 1}`, nome, local, sourceName: polo };
	});
	const datas = data.datas.map((rotulo) => {
		const match = rotulo.match(/^(\d{2})\/(\d{2})\/(\d{4})\b/);
		if (!match) {
			throw new Error(`Data inválida no arquivo data_v3.json: ${rotulo}`);
		}

		return { id: `${match[3]}-${match[2]}-${match[1]}`, rotulo, sourceLabel: rotulo };
	});
	const poloIdByName = new Map(polos.map((polo) => [polo.sourceName, polo.id]));
	const dateIdByLabel = new Map(datas.map((date) => [date.sourceLabel, date.id]));

	return {
		polos: polos.map(({ id, nome, local }) => ({ id, nome, local })),
		datas: datas.map(({ id, rotulo }) => ({ id, rotulo })),
		programacao: data.programacao.map((event, index) => {
			const poloId = poloIdByName.get(event.polo);
			const dateId = dateIdByLabel.get(event.data);
			if (!poloId || !dateId) {
				throw new Error(`Evento ${index + 1} referencia um polo ou data inexistente em data_v3.json.`);
			}

			return {
				id: `evento-${index + 1}`,
				poloId,
				data: dateId,
				hora: event.horario,
				titulo: event.titulo,
				convidados: event.convidados.split(/\r?\n/).map((guest) => guest.trim()).filter(Boolean)
			};
		})
	};
}

async function loadProgram() {
	try {
		const response = await fetch("data_v3.json?v=20261105", { cache: "no-cache" });
		if (!response.ok) {
			throw new Error(`Falha HTTP ao carregar a programação: ${response.status}`);
		}

		const data = normalizeProgramData(await response.json());
		if (!hasExpectedDataShape(data)) {
			throw new Error("O arquivo data_v3.json não possui o formato esperado.");
		}

		programData = data;
		populateFilters(programData);
		renderProgram();
	} catch (error) {
		console.error("Não foi possível carregar a programação.", error);
		programStatus.textContent = "Não foi possível carregar a programação agora. Tente novamente mais tarde.";
	}
}

poloFilter.addEventListener("change", () => {
	currentPage = 1;
	renderProgram();
});

dateFilter.addEventListener("change", () => {
	currentPage = 1;
	renderProgram();
});

previousPageButton.addEventListener("click", () => {
	if (currentPage > 1) {
		currentPage -= 1;
		renderProgram();
	}
});

nextPageButton.addEventListener("click", () => {
	currentPage += 1;
	renderProgram();
});

loadProgram();

const artistCarousel = document.querySelector(".card-carrousel");
const showsStatus = document.querySelector(".shows-status");

function formatArtistName(fileName) {
	const baseName = fileName.replace(/\.[^/.]+$/, "").replace(/[-_]+/g, " ").trim();
	if (!baseName) {
		return "Artista";
	}

	return baseName
		.split(/\s+/)
		.map((part) => part.charAt(0).toUpperCase() + part.slice(1))
		.join(" ");
}

function updateArtistCarouselState() {
	if (!artistCarousel) return;

	const hasSelectedCard = Boolean(artistCarousel.querySelector(".artist-card.is-selected"));
	const isHovering = artistCarousel.matches(":hover");
	artistCarousel.classList.toggle("is-paused", hasSelectedCard || isHovering);
}

function createArtistCard(artist, index) {
	const card = document.createElement("article");
	card.className = "artist-card";
	card.dataset.artistIndex = String(index);
	card.tabIndex = 0;
	card.setAttribute("role", "button");
	card.setAttribute("aria-label", `${artist.name}. ${artist.description || "Atração musical."}`);

	const image = document.createElement("img");
	image.src = artist.imagePath;
	image.alt = artist.name;
	image.loading = "eager";
	image.decoding = "async";
	card.append(image);

	const info = document.createElement("div");
	info.className = "artist-info";
	info.append(
		createTextElement("h3", "artist-name", artist.name),
		createTextElement("p", "artist-description", artist.description)
	);
	card.append(info);

	card.addEventListener("click", () => {
		const isSelected = card.classList.contains("is-selected");
		document.querySelectorAll(".artist-card").forEach((item) => {
			item.classList.toggle(
				"is-selected",
				!isSelected && item.dataset.artistIndex === card.dataset.artistIndex
			);
		});
		updateArtistCarouselState();
	});

	card.addEventListener("keydown", (event) => {
		if (event.key === "Enter" || event.key === " ") {
			event.preventDefault();
			card.click();
		}
	});

	return card;
}

async function loadArtistCarousel() {
	if (!artistCarousel) return;

	try {
		const artistsResponse = await fetch("./artists.json", { cache: "no-cache" });

		if (!artistsResponse.ok) {
			throw new Error(`Falha HTTP ao carregar as atrações: ${artistsResponse.status}`);
		}

		const artists = await artistsResponse.json();
		if (!Array.isArray(artists) || !artists.every((artist) => (
			artist
			&& typeof artist.filename === "string"
			&& typeof artist.name === "string"
			&& typeof artist.description === "string"
		))) {
			throw new Error("A lista de atrações não possui o formato esperado.");
		}

		const cardsData = artists
			.map((artist) => ({
				imagePath: `./imgs/artists/${encodeURIComponent(artist.filename)}`,
				name: artist.name || formatArtistName(artist.filename),
				description: artist.description
			}))
			.filter((artist) => Boolean(artist.imagePath && artist.name));

		if (cardsData.length === 0) {
			showsStatus.textContent = "Nenhuma atração disponível no momento.";
			return;
		}

		const track = document.createElement("div");
		track.className = "card-track";
		const cards = cardsData.map(createArtistCard);
		const clones = cards.map((card) => {
			const clone = card.cloneNode(true);
			clone.classList.add("is-clone");
			clone.setAttribute("aria-hidden", "true");
			clone.tabIndex = -1;
			clone.addEventListener("click", () => card.click());
			return clone;
		});

		track.append(...cards, ...clones);
		artistCarousel.replaceChildren(track);
		showsStatus.textContent = "";
		artistCarousel.addEventListener("pointerenter", updateArtistCarouselState);
		artistCarousel.addEventListener("pointerleave", () => {
			if (!artistCarousel.querySelector(".artist-card.is-selected")) {
				artistCarousel.classList.remove("is-paused");
			}
		});
		artistCarousel.addEventListener("focusin", () => artistCarousel.classList.add("is-paused"));
		artistCarousel.addEventListener("focusout", (event) => {
			const nextFocusTarget = event.relatedTarget;
			if (!nextFocusTarget || !artistCarousel.contains(nextFocusTarget)) {
				if (!artistCarousel.querySelector(".artist-card.is-selected")) {
					artistCarousel.classList.remove("is-paused");
				}
			}
		});
		updateArtistCarouselState();
	} catch (error) {
		console.error("Não foi possível carregar as atrações musicais.", error);
		artistCarousel.replaceChildren(showsStatus);
		showsStatus.textContent = "Não foi possível carregar as atrações agora.";
	}
}

loadArtistCarousel();

const authorsGrid = document.querySelector(".autores-grid");

function createAuthorCard(author) {
	const card = document.createElement("article");
	card.className = "autor-card";
	card.dataset.reveal = "card";
	card.tabIndex = 0;
	card.setAttribute("role", "button");
	card.setAttribute("aria-haspopup", "dialog");
	card.dataset.miniBio = author.mini_bio || "";

	const photo = document.createElement("img");
	photo.className = "autor-foto";
	photo.src = author.foto;
	photo.alt = `Foto de ${author.nome}`;
	photo.width = 480;
	photo.height = 480;
	photo.loading = "lazy";
	photo.decoding = "async";
	card.append(photo);

	card.append(createTextElement("h3", "autor-nome", author.nome));
	if (author.cidade) {
		card.append(createTextElement("p", "autor-local", author.cidade));
	}

	return card;
}

async function loadAuthors() {
	if (!authorsGrid) return;

	try {
		const response = await fetch("autores.json");
		if (!response.ok) {
			throw new Error(`Falha HTTP ao carregar autores: ${response.status}`);
		}

		const authors = await response.json();
		if (!Array.isArray(authors) || !authors.every((author) => (
			author
			&& typeof author.id === "string"
			&& typeof author.nome === "string"
			&& typeof author.cidade === "string"
			&& typeof author.foto === "string"
			&& (author.mini_bio === undefined || typeof author.mini_bio === "string")
			&& /^autores_uploads\/[a-f0-9]{32}\.(?:jpg|png|webp)$/.test(author.foto)
		))) {
			throw new Error("O arquivo autores.json não possui o formato esperado.");
		}

		if (authors.length === 0) {
			authorsGrid.replaceChildren(createTextElement(
				"p",
				"autores-empty",
				"Não há autores cadastrados no momento."
			));
			observeRevealElements(authorsGrid);
			return;
		}

		const sortedAuthors = [...authors].sort((first, second) => (
			first.nome.localeCompare(second.nome, "pt-BR", { sensitivity: "base" })
		));
		authorsGrid.replaceChildren(...sortedAuthors.map(createAuthorCard));
		observeRevealElements(authorsGrid);
	} catch (error) {
		console.error("Não foi possível carregar os autores.", error);
		authorsGrid.replaceChildren(createTextElement(
			"p",
			"autores-empty",
			"Não foi possível carregar os autores agora."
		));
		observeRevealElements(authorsGrid);
	}
}

loadAuthors();

const mesasGrid = document.querySelector(".mesas-grid");
const mesaDialog = document.querySelector(".mesa-dialog");
const mesaDialogTitle = document.querySelector(".mesa-dialog-titulo");
const mesaDialogParticipants = document.querySelector(".mesa-dialog-lista");
const mesaDialogDate = document.querySelector(".mesa-dialog-data-valor");
const mesaDialogTime = document.querySelector(".mesa-dialog-hora-valor");
const mesaDialogLocation = document.querySelector(".mesa-dialog-local-valor");
const mesaDialogDescription = document.querySelector(".mesa-dialog-ementa-texto");
const mesaDialogClose = document.querySelector(".mesa-dialog-fechar");
const mesasById = new Map();

function isValidMesaImagePath(path) {
	if (typeof path !== "string" || path.trim() === "") return false;

	try {
		const imageUrl = new URL(path.trim(), document.baseURI);
		return (imageUrl.protocol === "http:" || imageUrl.protocol === "https:")
			&& !imageUrl.username
			&& !imageUrl.password;
	} catch {
		return false;
	}
}

function isValidMesa(mesa) {
	return Boolean(
		mesa
		&& typeof mesa.id === "string"
		&& mesa.id.trim()
		&& typeof mesa.titulo === "string"
		&& mesa.titulo.trim()
		&& Array.isArray(mesa.participantes)
		&& mesa.participantes.every((participant) => typeof participant === "string")
		&& typeof mesa.ementa === "string"
		&& mesa.ementa.trim()
		&& typeof mesa.data === "string"
		&& mesa.data.trim()
		&& typeof mesa.hora === "string"
		&& mesa.hora.trim()
		&& typeof mesa.local === "string"
		&& mesa.local.trim()
	);
}

function createMesaCard(mesa, sequence) {
	const card = document.createElement("button");
	card.className = "mesa-card";
	card.type = "button";
	card.dataset.mesaId = mesa.id;
	card.setAttribute("aria-haspopup", "dialog");

	if (isValidMesaImagePath(mesa.imagemBackground)) {
		const image = document.createElement("img");
		image.className = "mesa-card-fundo";
		image.src = new URL(mesa.imagemBackground.trim(), document.baseURI).href;
		image.alt = "";
		image.loading = "lazy";
		image.decoding = "async";
		image.addEventListener("error", () => image.remove(), { once: true });
		card.append(image);
	} else if (mesa.imagemBackground !== undefined && mesa.imagemBackground !== "") {
		console.warn(`Imagem de fundo inválida para a mesa ${mesa.id}; será usado o fundo alternativo.`);
	}

	card.append(createTextElement("span", "mesa-card-numero", `MESA ${sequence}`));
	const info = document.createElement("div");
	info.className = "mesa-info";
	info.append(
		createTextElement("h3", "", mesa.titulo),
		createTextElement("p", "", `${mesa.data} - ${mesa.hora}`)
	);
	card.append(info);
	return card;
}

function openMesaDialog(mesa) {
	mesaDialogTitle.textContent = mesa.titulo;
	mesaDialogParticipants.replaceChildren(...mesa.participantes.map((participant) => (
		createTextElement("li", "", participant)
	)));
	mesaDialogDate.textContent = `Data: ${mesa.data}`;
	mesaDialogTime.textContent = `Horário: ${mesa.hora}`;
	mesaDialogLocation.textContent = `Local: ${mesa.local}`;
	mesaDialogDescription.textContent = mesa.ementa;
	mesaDialog.showModal();
	mesaDialogClose.focus();
}

async function loadMesas() {
	if (!mesasGrid) return;

	try {
		const response = await fetch("./mesas.json");
		if (!response.ok) {
			throw new Error(`Falha HTTP ao carregar mesas.json: ${response.status}`);
		}

		const data = await response.json();
		if (!data || !Array.isArray(data.mesas)) {
			throw new Error("O arquivo mesas.json não possui uma lista mesas válida.");
		}

		if (data.mesas.length === 0) {
			mesasGrid.replaceChildren(createTextElement(
				"p",
				"mesas-status",
				"Não há mesas cadastradas no momento."
			));
			return;
		}

		const cards = [];
		data.mesas.forEach((mesa, index) => {
			if (!isValidMesa(mesa) || mesasById.has(mesa.id)) {
				console.warn(`Registro de mesa inválido ou com ID duplicado na posição ${index + 1}.`);
				return;
			}

			mesasById.set(mesa.id, mesa);
			cards.push(createMesaCard(mesa, index + 1));
		});

		if (cards.length === 0) {
			mesasGrid.replaceChildren(createTextElement(
				"p",
				"mesas-status",
				"Não foi possível exibir as mesas cadastradas."
			));
			return;
		}

		mesasGrid.replaceChildren(...cards);
		observeRevealElements(mesasGrid);
	} catch (error) {
		console.error("Não foi possível carregar as mesas.", error);
		mesasGrid.replaceChildren(createTextElement(
			"p",
			"mesas-status",
			"Não foi possível carregar as mesas agora."
		));
	}
}

loadMesas();

if (mesasGrid && mesaDialog instanceof HTMLDialogElement
	&& mesaDialogTitle instanceof HTMLHeadingElement
	&& mesaDialogParticipants instanceof HTMLUListElement
	&& mesaDialogDate instanceof HTMLParagraphElement
	&& mesaDialogTime instanceof HTMLParagraphElement
	&& mesaDialogLocation instanceof HTMLParagraphElement
	&& mesaDialogDescription instanceof HTMLParagraphElement
	&& mesaDialogClose instanceof HTMLButtonElement) {
	mesasGrid.addEventListener("click", (event) => {
		if (!(event.target instanceof Element)) return;
		const card = event.target.closest(".mesa-card");
		if (!(card instanceof HTMLButtonElement)) return;

		const mesa = mesasById.get(card.dataset.mesaId);
		if (mesa) openMesaDialog(mesa);
	});

	mesaDialogClose.addEventListener("click", () => mesaDialog.close());
	mesaDialog.addEventListener("click", (event) => {
		if (event.target === mesaDialog) mesaDialog.close();
	});
}

const authorDialog = document.querySelector(".autor-dialog");
const authorDialogPhoto = document.querySelector(".autor-dialog-foto");
const authorDialogName = document.querySelector(".autor-dialog-nome");
const authorDialogBio = document.querySelector(".autor-dialog-bio");
const authorDialogClose = document.querySelector(".autor-dialog-fechar");

if (authorsGrid && authorDialog instanceof HTMLDialogElement
	&& authorDialogPhoto instanceof HTMLImageElement
	&& authorDialogName instanceof HTMLHeadingElement
	&& authorDialogBio instanceof HTMLParagraphElement
	&& authorDialogClose instanceof HTMLButtonElement) {
	function openAuthorDialog(card) {
		const photo = card.querySelector(".autor-foto");
		const name = card.querySelector(".autor-nome");
		if (!(photo instanceof HTMLImageElement) || !(name instanceof HTMLElement)) return;

		authorDialogPhoto.src = photo.currentSrc || photo.src;
		authorDialogPhoto.alt = photo.alt;
		authorDialogName.textContent = name.textContent;
		authorDialogBio.textContent = card.dataset.miniBio || "Mini-bio não cadastrada.";
		authorDialog.showModal();
		authorDialogClose.focus();
	}

	authorsGrid.addEventListener("click", (event) => {
		if (!(event.target instanceof Element)) return;
		const card = event.target.closest(".autor-card");
		if (card instanceof HTMLElement) openAuthorDialog(card);
	});

	authorsGrid.addEventListener("keydown", (event) => {
		if ((event.key !== "Enter" && event.key !== " ") || !(event.target instanceof HTMLElement)) return;
		const card = event.target.closest(".autor-card");
		if (!card) return;
		event.preventDefault();
		openAuthorDialog(card);
	});

	authorDialogClose.addEventListener("click", () => authorDialog.close());
	authorDialog.addEventListener("click", (event) => {
		if (event.target === authorDialog) authorDialog.close();
	});
}

const gallery = document.querySelector(".galeria-grid");
const galleryModal = document.querySelector(".galeria-modal");
const galleryModalImage = document.querySelector(".galeria-modal-imagem");
const galleryModalClose = document.querySelector(".galeria-modal-fechar");

if (gallery && galleryModal instanceof HTMLDialogElement
	&& galleryModalImage instanceof HTMLImageElement
	&& galleryModalClose instanceof HTMLButtonElement) {
	gallery.addEventListener("click", (event) => {
		if (!(event.target instanceof HTMLImageElement)) return;

		galleryModalImage.src = event.target.currentSrc || event.target.src;
		galleryModalImage.alt = event.target.alt;
		galleryModal.showModal();
	});

	galleryModalClose.addEventListener("click", () => {
		galleryModal.close();
	});

	galleryModal.addEventListener("click", (event) => {
		if (event.target === galleryModal) {
			galleryModal.close();
		}
	});
}
