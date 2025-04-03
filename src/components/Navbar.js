import '../assets/css/Navbar.scss';
import React, { useState } from 'react';
import 'font-awesome/css/font-awesome.min.css';

export default function Navbar() {

    const [isOpen, setIsOpen] = useState(false);
  
    const toggleDropdown = () => {
      setIsOpen(!isOpen);
    };

  return (
    <header className="Header MnBrCn BgA">
      <div className="MnBr EcBgA">
        <div className="Container">
          <figure className="Logo">
            <a href="/" title="Xem anime Vietsub" rel="home">
              <img
                src="https://cdn.animevietsub.lol/data/logo/logoz.png"
                alt="Logo"
              />
            </a>
          </figure>

          {/* <span className="Button MenuBtn AAShwHdd-lnk CXHd" data-shwhdd="Tp-Wp">
            <i></i>
            <i></i>
            <i></i>
          </span> */}
          {/* <span className="MenuBtnClose AAShwHdd-lnk CXHd" data-shwhdd="Tp-Wp"></span> */}

          <nav className="Menu">
              <ul>
                <li className="menu-item current-menu-item menu-item-home">
                  <a href="/">Trang chủ</a>
                </li>
                <li className="menu-item menu-item-has-children">
                  <i className="fa fa-chevron-down"></i>
                  <a href="#" onClick={toggleDropdown}>Dạng Anime</a>
                  <ul className={`sub-menu ${isOpen ? 'open' : ''}`}>
                    <li><a href="/anime-bo/">TV/Series</a></li>
                    <li><a href="/anime-le/">Movie/OVA</a></li>
                    <li><a href="/hoat-hinh-trung-quoc/">HH Trung Quốc</a></li>
                    <li><a href="/anime-sap-chieu/">Anime Sắp Chiếu</a></li>
                    <li><a href="/danh-sach/list-dang-chieu/">Anime Đang Chiếu</a></li>
                    <li><a href="/danh-sach/list-tron-bo/">Anime Trọn Bộ</a></li>
                  </ul>
                </li>

                <li className="menu-item menu-item-has-children">
                  <i className="fa fa-chevron-down"></i>
                  <a href="/bang-xep-hang.html">Top Anime</a>
                  <ul className="sub-menu">
                    <li><a href="/bang-xep-hang/day.html">Theo Ngày</a></li>
                    <li><a href="/bang-xep-hang/voted.html">Yêu Thích</a></li>
                    <li><a href="/bang-xep-hang/month.html">Theo Tháng</a></li>
                    <li><a href="/bang-xep-hang/season.html">Theo Mùa</a></li>
                    <li><a href="/bang-xep-hang/year.html">Theo Năm</a></li>
                  </ul>
                </li>
                <li className="menu-item menu-item-has-children">
                  <i className="fa fa-chevron-down"></i>
                  <a href="#">Thể loại</a>
                  <ul className="sub-menu">
                    <li><a href="/the-loai/hanh-dong/">Action</a></li>
                    <li><a href="/the-loai/phieu-luu/">Adventure</a></li>
                    {/* Add other categories here */}
                  </ul>
                </li>
                <li className="menu-item">
                  <a href="/anime/library/0-9/">Thư viện</a>
                </li>
                <li className="menu-item">
                  <a href="/lich-chieu-phim.html">Lịch chiếu</a>
                </li>
              </ul>
            </nav>

            <div className="Search" id="SearchDesktop">
              <form method="post" id="form-search" action="tim-kiem/">
                <label className="Form-Icon">
                  <input
                    type="text"
                    name="keyword"
                    placeholder="Tìm: tên tiếng nhật, anh, việt"
                    onKeyUp="onSearch(this.value,'SearchDesktop')"
                    id="searchkeyword"
                    autoComplete="off"
                  />
                  <button id="searchsubmit" type="submit">
                    <i className="fa fa-search"></i>
                  </button>
                </label>
              </form>
              <div className="search-suggest" style={{ display: 'none' }}>
                <ul
                  style={{ marginBottom: 0 }}
                  id="search-suggest-list"
                ></ul>
              </div>

              <div className="Login">
              <a
                href="/account/login/?_fxRef=https://animevietsub.lol/"
                className="Button StylA"
              >
                Đăng nhập
              </a>
            </div>
            </div>
        </div>
      </div>
    </header>
  );
}
